<?php

declare(strict_types=1);

namespace App\Tests\Command\ImportantMail;

use App\Command\ImportantMail\ImportantMailUnnotifiedCommand;
use App\Domain\Enum\Mail\ImportanceKind;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\ImportantMail\ImportantMail;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\ImportantMail\ImportantMailRepository;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportantMailUnnotifiedCommandTest extends TestCase
{
    private MockClock $clock;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        $this->clock   = new MockClock('2026-10-28 10:00:00', 'America/Sao_Paulo');
        $this->user    = new User();
        $this->account = new Account();
        $this->account->usr   = $this->user;
        $this->account->email = 'kayan@example.com';
    }

    public function testEmptyYieldsEmptyItemsAndValidJson(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAll')->willReturn([$this->user]);

        $mailRepo = $this->createMock(ImportantMailRepository::class);
        $mailRepo->method('findUnnotifiedFor')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $command = new ImportantMailUnnotifiedCommand(
            importantMailRepo: $mailRepo,
            userRepo: $userRepo,
            em: $em,
            clock: $this->clock,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $output = trim($tester->getDisplay());
        self::assertJson($output);

        $data = json_decode($output, true);
        self::assertSame([], $data['items']);
    }

    public function testOutputsUnnotifiedAndDoesNotMarkWithoutFlag(): void
    {
        $msg = $this->createMessage('fatura@nubank.com.br', 'Sua fatura fechou R$ 350,00 venc. 15/11');
        $item = ImportantMail::create($msg, ImportanceKind::Bill, 'rules', 'fatura nubank', $this->clock->now());

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAll')->willReturn([$this->user]);

        $mailRepo = $this->createMock(ImportantMailRepository::class);
        $mailRepo->method('findUnnotifiedFor')->willReturn([$item]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $command = new ImportantMailUnnotifiedCommand(
            importantMailRepo: $mailRepo,
            userRepo: $userRepo,
            em: $em,
            clock: $this->clock,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $output = trim($tester->getDisplay());
        $data = json_decode($output, true);

        self::assertCount(1, $data['items']);
        self::assertSame('bill', $data['items'][0]['kind']);
        self::assertSame('fatura@nubank.com.br', $data['items'][0]['from']);
        self::assertSame('R$ 350,00', $data['items'][0]['amount']);
        self::assertSame('15/11', $data['items'][0]['due']);
        self::assertNull($item->notifiedAt);
    }

    public function testMarksNotifiedWithFlag(): void
    {
        $msg = $this->createMessage('notifica@receita.fazenda.gov.br', 'Notificação IRPF');
        $item = ImportantMail::create($msg, ImportanceKind::Government, 'rules', 'gov.br', $this->clock->now());

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAll')->willReturn([$this->user]);

        $mailRepo = $this->createMock(ImportantMailRepository::class);
        $mailRepo->method('findUnnotifiedFor')->willReturn([$item]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $command = new ImportantMailUnnotifiedCommand(
            importantMailRepo: $mailRepo,
            userRepo: $userRepo,
            em: $em,
            clock: $this->clock,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--mark-notified' => true]);

        self::assertSame(0, $exitCode);
        self::assertNotNull($item->notifiedAt);
        self::assertEquals($this->clock->now(), $item->notifiedAt);
    }

    private function createMessage(string $from, string $subject): Message
    {
        $msg = new Message();
        $msg->account     = $this->account;
        $msg->fromAddress = $from;
        $msg->subject     = $subject;
        $msg->bodyText    = $subject;
        $msg->receivedAt  = new DateTimeImmutable('2026-10-28 09:30:00');

        $inbox = new Label();
        $inbox->role = LabelRole::Inbox;
        $msg->addLabel($inbox);

        return $msg;
    }
}
