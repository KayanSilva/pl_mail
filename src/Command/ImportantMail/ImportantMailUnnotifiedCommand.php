<?php

declare(strict_types=1);

namespace App\Command\ImportantMail;

use App\Entity\ImportantMail\ImportantMail;
use App\Repository\ImportantMail\ImportantMailRepository;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emits a single-line JSON list of all unnotified important emails.
 *
 * Consumed by the Chief orchestrator in my-one-person-business.
 * If --mark-notified is passed, sets notified_at = now on each record and flushes.
 */
#[AsCommand(
    name: 'app:important:unnotified',
    description: 'Outputs unnotified important emails as single-line JSON, optionally marking them as notified',
)]
final class ImportantMailUnnotifiedCommand extends Command
{
    private ClockInterface $clock;

    public function __construct(
        private readonly ImportantMailRepository $importantMailRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        ?ClockInterface $clock = null,
    ) {
        parent::__construct();
        $this->clock = $clock ?? new NativeClock();
    }

    protected function configure(): void
    {
        $this->addOption(
            'mark-notified',
            null,
            InputOption::VALUE_NONE,
            'Mark retrieved messages as notified in the database',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();
        $markNotified = (bool) $input->getOption('mark-notified');

        $users = $this->userRepo->findAll();
        /** @var list<ImportantMail> $unnotifiedList */
        $unnotifiedList = [];

        foreach ($users as $user) {
            $unnotifiedList = array_merge($unnotifiedList, $this->importantMailRepo->findUnnotifiedFor($user));
        }

        $items = [];
        foreach ($unnotifiedList as $item) {
            $msg = $item->message;
            $receivedAt = $msg->receivedAt ?? $msg->sentAt ?? $item->classifiedAt;

            $items[] = [
                'id'          => $item->id,
                'message_id'  => $msg->id,
                'kind'        => $item->kind->value,
                'subject'     => $msg->subject,
                'from'        => $msg->fromAddress,
                'received_at' => $receivedAt->format(DateTimeImmutable::ATOM),
                'amount'      => $this->extractAmount($msg->subject, $msg->bodyText),
                'due'         => $this->extractDue($msg->subject, $msg->bodyText),
            ];

            if ($markNotified) {
                $item->markNotified($now);
            }
        }

        if ($markNotified && !empty($unnotifiedList)) {
            $this->em->flush();
        }

        $payload = [
            'count' => count($items),
            'items' => $items,
        ];

        $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

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
