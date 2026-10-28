<?php

declare(strict_types=1);

namespace App\Command\ImportantMail;

use App\Domain\Interface\FamilyResolverInterface;
use App\Entity\ImportantMail\ImportantMail;
use App\Repository\ImportantMail\ImportantMailRepository;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Emits a single-line JSON digest of all unresolved important emails.
 *
 * Consumed by the morning briefing connector in my-one-person-business.
 *
 * Invariants:
 * - Emits exactly ONE line of JSON to stdout.
 * - Items with after_hours=true come first (overnight messages without prior notification).
 * - Within each group, items are ordered oldest to newest.
 * - Any diagnostics or warnings must go to stderr.
 */
#[AsCommand(
    name: 'app:important:digest',
    description: 'Outputs unresolved important emails as a single-line JSON digest',
)]
final class ImportantMailDigestCommand extends Command
{
    private ClockInterface $clock;
    private DateTimeZone $tz;

    public function __construct(
        private readonly ImportantMailRepository $importantMailRepo,
        private readonly FamilyResolverInterface $familyResolver,
        private readonly UserRepository $userRepo,
        ?ClockInterface $clock = null,
        #[Autowire('%env(default::IMPORTANT_MAIL_TZ)%')]
        string $timezone = 'America/Sao_Paulo',
    ) {
        parent::__construct();
        $this->clock = $clock ?? new NativeClock();

        $tzName = '' !== trim($timezone) ? trim($timezone) : 'America/Sao_Paulo';
        try {
            $this->tz = new DateTimeZone($tzName);
        } catch (\Throwable) {
            $this->tz = new DateTimeZone('America/Sao_Paulo');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now()->setTimezone($this->tz);

        // Night window calculation (America/Sao_Paulo):
        // If current hour >= 20, night started today at 20:00 and ends tomorrow at 06:00.
        // Otherwise (morning/daytime), night started yesterday at 20:00 and ended today at 06:00.
        $hour = (int) $now->format('H');
        if ($hour >= 20) {
            $nightStart = $now->setTime(20, 0, 0);
            $nightEnd   = $nightStart->modify('+10 hours');
        } else {
            $nightEnd   = $now->setTime(6, 0, 0);
            $nightStart = $nightEnd->modify('-10 hours');
        }

        $users = $this->userRepo->findAll();
        $familyTagFound = false;
        /** @var list<ImportantMail> $unresolvedList */
        $unresolvedList = [];

        foreach ($users as $user) {
            if ($this->familyResolver->hasAnyFamilyContact($user)) {
                $familyTagFound = true;
            }
            $userUnresolved = $this->importantMailRepo->findUnresolvedFor($user);
            foreach ($userUnresolved as $item) {
                $unresolvedList[] = $item;
            }
        }

        $items = [];
        foreach ($unresolvedList as $item) {
            $msg = $item->message;
            $receivedAt = ($msg->receivedAt ?? $msg->sentAt ?? $item->classifiedAt)->setTimezone($this->tz);

            $isAfterHours = ($receivedAt >= $nightStart && $receivedAt <= $nightEnd);
            $ageHours     = max(0, (int) floor(($now->getTimestamp() - $receivedAt->getTimestamp()) / 3600));

            $amount = $this->extractAmount($msg->subject, $msg->bodyText);
            $due    = $this->extractDue($msg->subject, $msg->bodyText);

            $items[] = [
                'kind'         => $item->kind->value,
                'from'         => (string) $msg->fromAddress,
                'subject'      => $msg->subject ?? '',
                'received_at'  => $receivedAt->format(\DateTimeInterface::ATOM),
                'age_hours'    => $ageHours,
                'after_hours'  => $isAfterHours,
                'notified'     => null !== $item->notifiedAt,
                'amount'       => $amount,
                'due'          => $due,
                '_received_ts' => $receivedAt->getTimestamp(),
            ];
        }

        // Sorting: after_hours first, then oldest to newest
        usort($items, static function (array $a, array $b): int {
            if ($a['after_hours'] !== $b['after_hours']) {
                return $a['after_hours'] ? -1 : 1;
            }
            return ((int) $a['_received_ts']) <=> ((int) $b['_received_ts']);
        });

        // Strip temporary sort key
        $cleanItems = array_map(static function (array $item): array {
            unset($item['_received_ts']);
            return $item;
        }, $items);

        $payload = [
            'generated_at'     => $now->format(\DateTimeInterface::ATOM),
            'family_tag_found' => $familyTagFound,
            'items'            => $cleanItems,
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $output->writeln($json);

        return Command::SUCCESS;
    }

    /**
     * Extracts currency amount in Brazilian Real (R$).
     * shortcut: regex, trocar por extração pelo modelo se errar demais.
     */
    private function extractAmount(?string $subject, ?string $body): ?string
    {
        $text = trim(($subject ?? '') . "\n" . ($body ?? ''));
        if ('' === $text) {
            return null;
        }

        if (preg_match('/R\$\s?[\d.]+,\d{2}/u', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Extracts due date from subject or body text.
     * shortcut: regex, trocar por extração pelo modelo se errar demais.
     */
    private function extractDue(?string $subject, ?string $body): ?string
    {
        $text = trim(($subject ?? '') . "\n" . ($body ?? ''));
        if ('' === $text) {
            return null;
        }

        if (preg_match('/venc\w*\D{0,15}(\d{2}\/\d{2}(?:\/\d{2,4})?)/iu', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
