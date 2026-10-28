<?php

declare(strict_types=1);

namespace App\Tests\Command\ImportantMail;

use App\Command\ImportantMail\ImportantMailDigestCommand;
use App\Domain\Enum\Mail\ArrivalPlace;
use App\Domain\Enum\Mail\ImportanceKind;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Interface\FamilyResolverInterface;
use App\Entity\ImportantMail\ImportantMail;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\ImportantMail\ImportantMailRepository;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportantMailDigestCommandTest extends TestCase
{
    private DateTimeZone $tz;
    private MockClock $clock;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        $this->tz      = new DateTimeZone('America/Sao_Paulo');
        // Morning at 06:00:00 Brasília
        $this->clock   = new MockClock('2026-10-28 06:00:00', 'America/Sao_Paulo');
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
        $mailRepo->method('findUnresolvedFor')->willReturn([]);

        $family = $this->createMock(FamilyResolverInterface::class);
        $family->method('hasAnyFamilyContact')->willReturn(true);

        $command = new ImportantMailDigestCommand(
            importantMailRepo: $mailRepo,
            familyResolver: $family,
            userRepo: $userRepo,
            clock: $this->clock,
            timezone: 'America/Sao_Paulo',
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $output = trim($tester->getDisplay());
        self::assertJson($output);

        $data = json_decode($output, true);
        self::assertSame([], $data['items']);
        self::assertTrue($data['family_tag_found']);
        self::assertArrayHasKey('generated_at', $data);
    }

    public function testResolvedMessagesAreExcluded(): void
    {
        // 1. Unresolved: seen in inbox
        $msg1 = $this->createMessage(
            from: 'notifica@receita.fazenda.gov.br',
            subject: 'Notificação Receita',
            receivedAt: '2026-10-28 05:00:00',
            seen: true,
            inInbox: true,
        );
        $item1 = ImportantMail::create($msg1, ImportanceKind::Government, 'rules', 'gov');

        // 2. Unresolved: unseen archived
        $msg2 = $this->createMessage(
            from: 'mae@familia.test',
            subject: 'Oi filho',
            receivedAt: '2026-10-27 15:00:00',
            seen: false,
            inInbox: false,
        );
        $item2 = ImportantMail::create($msg2, ImportanceKind::Family, 'rules', 'family');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAll')->willReturn([$this->user]);

        $mailRepo = $this->createMock(ImportantMailRepository::class);
        // findUnresolvedFor returns only unresolved items
        $mailRepo->method('findUnresolvedFor')->willReturn([$item1, $item2]);

        $family = $this->createMock(FamilyResolverInterface::class);
        $family->method('hasAnyFamilyContact')->willReturn(true);

        $command = new ImportantMailDigestCommand(
            importantMailRepo: $mailRepo,
            familyResolver: $family,
            userRepo: $userRepo,
            clock: $this->clock,
            timezone: 'America/Sao_Paulo',
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $data = json_decode(trim($tester->getDisplay()), true);
        self::assertCount(2, $data['items']);
        self::assertSame('government', $data['items'][0]['kind']);
        self::assertSame('family', $data['items'][1]['kind']);
    }

    public function testAfterHoursGoFirst(): void
    {
        // Daytime email: 2026-10-27 14:00 (not after-hours)
        $dayMsg = $this->createMessage(
            from: 'notifica@receita.fazenda.gov.br',
            subject: 'Dia',
            receivedAt: '2026-10-27 14:00:00',
        );
        $dayItem = ImportantMail::create($dayMsg, ImportanceKind::Government, 'rules', 'gov');

        // Night email: 2026-10-27 22:30 (after-hours)
        $nightMsg = $this->createMessage(
            from: 'fatura@nubank.com.br',
            subject: 'Noite',
            receivedAt: '2026-10-27 22:30:00',
        );
        $nightItem = ImportantMail::create($nightMsg, ImportanceKind::Bill, 'rules', 'bill');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAll')->willReturn([$this->user]);

        $mailRepo = $this->createMock(ImportantMailRepository::class);
        // Repository returns them in arbitrary order
        $mailRepo->method('findUnresolvedFor')->willReturn([$dayItem, $nightItem]);

        $family = $this->createMock(FamilyResolverInterface::class);
        $family->method('hasAnyFamilyContact')->willReturn(true);

        $command = new ImportantMailDigestCommand(
            importantMailRepo: $mailRepo,
            familyResolver: $family,
            userRepo: $userRepo,
            clock: $this->clock,
            timezone: 'America/Sao_Paulo',
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $data = json_decode(trim($tester->getDisplay()), true);
        self::assertCount(2, $data['items']);
        // Night email should be sorted FIRST
        self::assertTrue($data['items'][0]['after_hours']);
        self::assertSame('bill', $data['items'][0]['kind']);

        // Day email should be second
        self::assertFalse($data['items'][1]['after_hours']);
        self::assertSame('government', $data['items'][1]['kind']);
    }

    public function testAmountAndDueExtracted(): void
    {
        $msg = $this->createMessage(
            from: 'fatura@nubank.com.br',
            subject: 'Sua fatura fechou',
            receivedAt: '2026-10-27 21:00:00',
            bodyText: "Olá! Fatura R$ 1.234,56 vencimento 10/11 para pagamento.",
        );
        $item = ImportantMail::create($msg, ImportanceKind::Bill, 'rules', 'bill');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAll')->willReturn([$this->user]);

        $mailRepo = $this->createMock(ImportantMailRepository::class);
        $mailRepo->method('findUnresolvedFor')->willReturn([$item]);

        $family = $this->createMock(FamilyResolverInterface::class);
        $family->method('hasAnyFamilyContact')->willReturn(true);

        $command = new ImportantMailDigestCommand(
            importantMailRepo: $mailRepo,
            familyResolver: $family,
            userRepo: $userRepo,
            clock: $this->clock,
            timezone: 'America/Sao_Paulo',
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $data = json_decode(trim($tester->getDisplay()), true);
        self::assertSame('R$ 1.234,56', $data['items'][0]['amount']);
        self::assertSame('10/11', $data['items'][0]['due']);
    }

    public function testNoFamilyContactSetsFlagFalse(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAll')->willReturn([$this->user]);

        $mailRepo = $this->createMock(ImportantMailRepository::class);
        $mailRepo->method('findUnresolvedFor')->willReturn([]);

        $family = $this->createMock(FamilyResolverInterface::class);
        $family->method('hasAnyFamilyContact')->willReturn(false);

        $command = new ImportantMailDigestCommand(
            importantMailRepo: $mailRepo,
            familyResolver: $family,
            userRepo: $userRepo,
            clock: $this->clock,
            timezone: 'America/Sao_Paulo',
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $data = json_decode(trim($tester->getDisplay()), true);
        self::assertFalse($data['family_tag_found']);
    }

    public function testOutputIsSingleLineJson(): void
    {
        $msg = $this->createMessage(
            from: 'notifica@receita.fazenda.gov.br',
            subject: 'Aviso',
            receivedAt: '2026-10-28 04:00:00',
        );
        $item = ImportantMail::create($msg, ImportanceKind::Government, 'rules', 'gov');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAll')->willReturn([$this->user]);

        $mailRepo = $this->createMock(ImportantMailRepository::class);
        $mailRepo->method('findUnresolvedFor')->willReturn([$item]);

        $family = $this->createMock(FamilyResolverInterface::class);
        $family->method('hasAnyFamilyContact')->willReturn(true);

        $command = new ImportantMailDigestCommand(
            importantMailRepo: $mailRepo,
            familyResolver: $family,
            userRepo: $userRepo,
            clock: $this->clock,
            timezone: 'America/Sao_Paulo',
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $rawOutput = $tester->getDisplay();
        $lines = explode("\n", trim($rawOutput));
        self::assertCount(1, $lines, 'Output must be exactly one line of JSON');
    }

    private function createMessage(
        string $from,
        string $subject,
        string $receivedAt,
        ?string $bodyText = null,
        bool $seen = false,
        bool $inInbox = true,
    ): Message {
        $msg              = new Message();
        $msg->account     = $this->account;
        $msg->fromAddress = $from;
        $msg->subject     = $subject;
        $msg->bodyText    = $bodyText;
        $msg->receivedAt  = new DateTimeImmutable($receivedAt, $this->tz);
        $msg->arrivedIn   = ArrivalPlace::Inbox;

        if ($seen) {
            $msg->seenAt = new DateTimeImmutable($receivedAt, $this->tz);
        }

        if ($inInbox) {
            $inboxLabel       = new Label();
            $inboxLabel->role = LabelRole::Inbox;
            $msg->labels->add($inboxLabel);
        }

        return $msg;
    }
}
