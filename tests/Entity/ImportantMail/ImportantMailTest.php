<?php

declare(strict_types=1);

namespace App\Tests\Entity\ImportantMail;

use App\Domain\Enum\Mail\ImportanceKind;
use App\Entity\ImportantMail\ImportantMail;
use App\Entity\Mail\Message;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Persisted record of an incoming message flagged as high-priority.
 */
final class ImportantMailTest extends TestCase
{
    public function testCreateWithValidSource(): void
    {
        $message = new Message();
        $record  = ImportantMail::create(
            message: $message,
            kind: ImportanceKind::Government,
            source: 'rules',
            reason: 'domain gov.br',
        );

        self::assertSame($message, $record->message);
        self::assertSame(ImportanceKind::Government, $record->kind);
        self::assertSame('rules', $record->source);
        self::assertSame('domain gov.br', $record->reason);
        self::assertNull($record->notifiedAt);
        self::assertInstanceOf(DateTimeImmutable::class, $record->classifiedAt);
    }

    public function testCreateRejectsUnknownSource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $message = new Message();
        ImportantMail::create(
            message: $message,
            kind: ImportanceKind::Bill,
            source: 'magic',
            reason: 'invalid',
        );
    }

    public function testMarkNotifiedSetsTimestampOnce(): void
    {
        $message = new Message();
        $record  = ImportantMail::create($message, ImportanceKind::Family, 'rules', 'tag Family');

        $t1 = new DateTimeImmutable('2026-10-28 10:00:00');
        $record->markNotified($t1);

        self::assertSame($t1, $record->notifiedAt);

        // A second call is idempotent: does not overwrite the initial timestamp
        $t2 = new DateTimeImmutable('2026-10-28 11:00:00');
        $record->markNotified($t2);

        self::assertSame($t1, $record->notifiedAt);
    }
}
