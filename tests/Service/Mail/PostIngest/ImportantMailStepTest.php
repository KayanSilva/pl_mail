<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Enum\Mail\ArrivalPlace;
use App\Domain\Enum\Mail\ImportanceKind;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Interface\FamilyResolverInterface;
use App\Domain\Interface\ModelClassifierInterface;
use App\Domain\Interface\NotifyWindowInterface;
use App\Domain\Interface\TelegramNotifierInterface;
use App\Entity\ImportantMail\ImportantMail;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\ImportantMail\ImportantMailRepository;
use App\Service\ImportantMail\RuleClassifier;
use App\Service\Mail\PostIngest\ImportantMailStep;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ImportantMailStepTest extends TestCase
{
    private DateTimeImmutable $since;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        $this->since   = new DateTimeImmutable('2026-10-28 00:00:00');
        $this->user    = new User();
        $this->account = new Account();
        $this->account->usr   = $this->user;
        $this->account->email = 'kayan@example.com';
    }

    public function testSkippedWhenNotLive(): void
    {
        $msg = $this->createMessage('notifica@receita.fazenda.gov.br', '2026-10-28 10:00:00');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $step = $this->buildStep(em: $em);
        $step->afterCommit(new PostIngestResult([$msg], [$this->account], [], live: false));
    }

    public function testSkippedWhenReceivedBeforeSince(): void
    {
        $msg = $this->createMessage('notifica@receita.fazenda.gov.br', '2026-10-27 23:59:59');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $step = $this->buildStep(em: $em);
        $step->afterCommit(new PostIngestResult([$msg], [$this->account], [], live: true));
    }

    public function testGovMailInsideWindowIsPersistedAndNotified(): void
    {
        $msg = $this->createMessage('notifica@receita.fazenda.gov.br', '2026-10-28 10:00:00');

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted = $entity;
        });
        $em->expects(self::once())->method('flush');

        $notifier = $this->createMock(TelegramNotifierInterface::class);
        $notifier->expects(self::once())->method('send');

        $window = $this->createMock(NotifyWindowInterface::class);
        $window->method('isOpen')->willReturn(true);

        $step = $this->buildStep(em: $em, notifier: $notifier, window: $window);
        $step->afterCommit(new PostIngestResult([$msg], [$this->account], [], live: true));

        self::assertInstanceOf(ImportantMail::class, $persisted);
        self::assertSame(ImportanceKind::Government, $persisted->kind);
        self::assertNotNull($persisted->notifiedAt);
    }

    public function testGovMailAtNightIsPersistedWithoutTelegramNotification(): void
    {
        $msg = $this->createMessage('notifica@receita.fazenda.gov.br', '2026-10-28 21:00:00');

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted = $entity;
        });

        $notifier = $this->createMock(TelegramNotifierInterface::class);
        $notifier->expects(self::never())->method('send');

        $window = $this->createMock(NotifyWindowInterface::class);
        $window->method('isOpen')->willReturn(false);

        $step = $this->buildStep(em: $em, notifier: $notifier, window: $window);
        $step->afterCommit(new PostIngestResult([$msg], [$this->account], [], live: true));

        self::assertInstanceOf(ImportantMail::class, $persisted);
        self::assertNull($persisted->notifiedAt);
    }

    public function testTelegramFailureStillLeavesRowPersistedWithNullNotifiedAt(): void
    {
        $msg = $this->createMessage('notifica@receita.fazenda.gov.br', '2026-10-28 10:00:00');

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted = $entity;
        });

        $notifier = $this->createMock(TelegramNotifierInterface::class);
        $notifier->method('send')->willThrowException(new \RuntimeException('Telegram offline'));

        $window = $this->createMock(NotifyWindowInterface::class);
        $window->method('isOpen')->willReturn(true);

        $step = $this->buildStep(em: $em, notifier: $notifier, window: $window);
        $step->afterCommit(new PostIngestResult([$msg], [$this->account], [], live: true));

        self::assertInstanceOf(ImportantMail::class, $persisted);
        self::assertNull($persisted->notifiedAt);
    }

    public function testSecondRunDoesNotDuplicateRow(): void
    {
        $msg = $this->createMessage('notifica@receita.fazenda.gov.br', '2026-10-28 10:00:00');

        $existing = ImportantMail::create($msg, ImportanceKind::Government, 'rules', 'prev');
        $repo     = $this->createMock(ImportantMailRepository::class);
        $repo->method('findByMessage')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $step = $this->buildStep(em: $em, repo: $repo);
        $step->afterCommit(new PostIngestResult([$msg], [$this->account], [], live: true));
    }

    public function testBlankSinceDisablesTheStep(): void
    {
        $msg = $this->createMessage('notifica@receita.fazenda.gov.br', '2026-10-28 10:00:00');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $step = $this->buildStep(em: $em, since: null);
        $step->afterCommit(new PostIngestResult([$msg], [$this->account], [], live: true));
    }

    private function createMessage(string $from, string $receivedAt): Message
    {
        $msg              = new Message();
        $msg->account     = $this->account;
        $msg->fromAddress = $from;
        $msg->receivedAt  = new DateTimeImmutable($receivedAt);
        $msg->arrivedIn   = ArrivalPlace::Inbox;

        $inboxLabel       = new Label();
        $inboxLabel->role = LabelRole::Inbox;
        $msg->labels->add($inboxLabel);

        return $msg;
    }

    private function buildStep(
        ?EntityManagerInterface $em = null,
        ?ImportantMailRepository $repo = null,
        ?TelegramNotifierInterface $notifier = null,
        ?NotifyWindowInterface $window = null,
        ?DateTimeImmutable $since = new DateTimeImmutable('2026-10-28 00:00:00'),
    ): ImportantMailStep {
        $em       ??= $this->createMock(EntityManagerInterface::class);
        $repo     ??= $this->createMock(ImportantMailRepository::class);
        $notifier ??= $this->createMock(TelegramNotifierInterface::class);
        $window   ??= $this->createMock(NotifyWindowInterface::class);

        $rules = new RuleClassifier(
            govDomains: ['gov.br'],
            govSenderWords: ['receita'],
            billWords: ['fatura'],
            billSenderWords: ['nubank'],
        );

        $family = $this->createMock(FamilyResolverInterface::class);
        $family->method('emailsFor')->willReturn([]);

        $model = $this->createMock(ModelClassifierInterface::class);
        $model->method('isEnabled')->willReturn(false);

        return new ImportantMailStep(
            rules: $rules,
            family: $family,
            model: $model,
            importantMailRepo: $repo,
            notifier: $notifier,
            window: $window,
            em: $em,
            logger: new NullLogger(),
            since: $since,
        );
    }
}
