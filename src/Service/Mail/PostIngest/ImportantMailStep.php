<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Enum\Mail\ArrivalPlace;
use App\Domain\Enum\Mail\ImportanceKind;
use App\Domain\Interface\FamilyResolverInterface;
use App\Domain\Interface\ModelClassifierInterface;
use App\Domain\Interface\NotifyWindowInterface;
use App\Domain\Interface\PostIngestStepInterface;
use App\Domain\Interface\TelegramNotifierInterface;
use App\Entity\ImportantMail\ImportantMail;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\ImportantMail\ImportantMailRepository;
use App\Service\ImportantMail\RuleClassifier;
use App\Service\ImportantMail\TelegramNotifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * High-priority mail orchestrator that runs at the end of each post-ingest batch.
 *
 * For each candidate message:
 * 1. Filters: live sync only, arrived in Inbox, not set aside, not seen, received >= since.
 * 2. Classifies: Family (by Google Contacts tags) > Rules (Gov/Bill) > Model (paid only).
 * 3. Persists an important_mail row (idempotent, never duplicates).
 * 4. If current time is within the allowed window (06:00 to 20:00), dispatches an immediate
 *    Telegram alert and marks notified_at.
 *
 * INVARIANT: failures in classification or Telegram dispatch are logged and NEVER
 * crash the mail ingest pipeline.
 */
final readonly class ImportantMailStep implements PostIngestStepInterface
{
    private ?DateTimeImmutable $since;
    private ClockInterface $clock;
    private LoggerInterface $logger;

    public function __construct(
        private RuleClassifier $rules,
        private FamilyResolverInterface $family,
        private ModelClassifierInterface $model,
        private ImportantMailRepository $importantMailRepo,
        private TelegramNotifierInterface $notifier,
        private NotifyWindowInterface $window,
        private EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
        #[Autowire('%env(default::IMPORTANT_MAIL_SINCE)%')]
        string|DateTimeImmutable|null $since = null,
        ?ClockInterface $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock  = $clock ?? new NativeClock();

        if ($since instanceof DateTimeImmutable) {
            $this->since = $since;
        } elseif (is_string($since) && '' !== trim($since)) {
            $parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, trim($since));
            if (false === $parsed) {
                try {
                    $parsed = new DateTimeImmutable(trim($since));
                } catch (\Throwable) {
                    $parsed = null;
                }
            }
            $this->since = $parsed;
        } else {
            $this->since = null;
        }
    }

    public function afterCommit(PostIngestResult $result): void
    {
        // Step is disabled if IMPORTANT_MAIL_SINCE is unset or if this is bulk import
        if (null === $this->since || !$result->live) {
            return;
        }

        $now   = $this->clock->now();
        $dirty = false;

        /** @var array<int, array<string, true>> $familyEmailsByUserCache */
        $familyEmailsByUserCache = [];

        foreach ($result->messages as $message) {
            try {
                if (!$this->isCandidate($message)) {
                    continue;
                }

                // Skip if already tracked
                if (null !== $this->importantMailRepo->findByMessage($message)) {
                    continue;
                }

                $user = $message->account->usr;
                $familyEmails = [];
                if ($user instanceof User) {
                    $userId = (int) ($user->id ?? 0);
                    if (!isset($familyEmailsByUserCache[$userId])) {
                        $familyEmailsByUserCache[$userId] = $this->family->emailsFor($user);
                    }
                    $familyEmails = $familyEmailsByUserCache[$userId];
                }

                $fromClean = mb_strtolower(trim((string) $message->fromAddress));
                $isFamily  = isset($familyEmails[$fromClean]);

                [$kind, $source, $reason] = $this->classify($message, $isFamily);

                if (null === $kind) {
                    continue;
                }

                $record = ImportantMail::create(
                    message: $message,
                    kind: $kind,
                    source: $source,
                    reason: $reason,
                    classifiedAt: $now,
                );

                // Immediate notification window: 06h to 20h
                if ($this->window->isOpen($now)) {
                    try {
                        $text = TelegramNotifier::format($message, $kind);
                        $this->notifier->send($text);
                        $record->markNotified($now);
                    } catch (\Throwable $e) {
                        $this->logger->error('ImportantMailStep: falha ao enviar alerta Telegram', [
                            'messageId' => $message->id,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }

                $this->em->persist($record);
                $dirty = true;
            } catch (\Throwable $e) {
                $this->logger->error('ImportantMailStep: erro ao processar mensagem', [
                    'messageId' => $message->id ?? null,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($dirty) {
            try {
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('ImportantMailStep: falha ao persistir important_mail rows', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function isCandidate(Message $message): bool
    {
        // Must be in Inbox, not set aside (spam/trash), not already seen
        if (!$message->isInInbox() || $message->isSetAside() || null !== $message->seenAt) {
            return false;
        }

        // Must have arrived directly in Inbox
        if (ArrivalPlace::Inbox !== $message->arrivedIn) {
            return false;
        }

        // Must have been received at or after the cutoff timestamp
        $receivedAt = $message->receivedAt ?? $message->sentAt;
        if (null === $receivedAt || null === $this->since || $receivedAt < $this->since) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: ?ImportanceKind, 1: string, 2: string} [kind, source, reason]
     */
    private function classify(Message $message, bool $isFamily): array
    {
        $from    = (string) $message->fromAddress;
        $subject = $message->subject;
        $body    = $message->bodyText;

        // 1. Rules first
        $ruleKind = $this->rules->classify($from, $subject, $body, $isFamily);
        if (null !== $ruleKind) {
            $reason = ImportanceKind::Family === $ruleKind
                ? 'contato marcado com tag de família'
                : 'regras de domínio/palavras-chave';
            return [$ruleKind, ImportantMail::SOURCE_RULES, $reason];
        }

        // 2. Model fallback: only for primary messages if model is enabled and safe
        if ($this->model->isEnabled()) {
            $modelKind = $this->model->classify($from, $subject, $body);
            if (null !== $modelKind) {
                return [$modelKind, ImportantMail::SOURCE_MODEL, 'classificado por modelo de linguagem'];
            }
        }

        return [null, '', ''];
    }
}
