<?php

declare(strict_types=1);

namespace App\Tests\Service\ImportantMail;

use App\Service\ImportantMail\NotifyWindow;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Window during which immediate Telegram notifications are allowed.
 *
 * 06:00:00 inclusive to 20:00:00 exclusive in the configured timezone
 * (America/Sao_Paulo).
 */
final class NotifyWindowTest extends TestCase
{
    private NotifyWindow $window;

    protected function setUp(): void
    {
        $this->window = new NotifyWindow('America/Sao_Paulo');
    }

    public function testExactlyAtSixAmIsOpen(): void
    {
        $at = new DateTimeImmutable('2026-10-28 06:00:00', new \DateTimeZone('America/Sao_Paulo'));
        self::assertTrue($this->window->isOpen($at));
    }

    public function testOneSecondBeforeSixAmIsClosed(): void
    {
        $at = new DateTimeImmutable('2026-10-28 05:59:59', new \DateTimeZone('America/Sao_Paulo'));
        self::assertFalse($this->window->isOpen($at));
    }

    public function testMidDayIsOpen(): void
    {
        $at = new DateTimeImmutable('2026-10-28 14:30:00', new \DateTimeZone('America/Sao_Paulo'));
        self::assertTrue($this->window->isOpen($at));
    }

    public function testOneSecondBeforeEightPmIsOpen(): void
    {
        $at = new DateTimeImmutable('2026-10-28 19:59:59', new \DateTimeZone('America/Sao_Paulo'));
        self::assertTrue($this->window->isOpen($at));
    }

    public function testExactlyAtEightPmIsClosed(): void
    {
        $at = new DateTimeImmutable('2026-10-28 20:00:00', new \DateTimeZone('America/Sao_Paulo'));
        self::assertFalse($this->window->isOpen($at));
    }

    public function testNightIsClosed(): void
    {
        $at = new DateTimeImmutable('2026-10-28 23:45:00', new \DateTimeZone('America/Sao_Paulo'));
        self::assertFalse($this->window->isOpen($at));
    }

    public function testUtcTimestampIsConvertedToTimezone(): void
    {
        // 08:59:59 UTC = 05:59:59 in Sao Paulo (UTC-3) -> closed
        $at1 = new DateTimeImmutable('2026-10-28 08:59:59', new \DateTimeZone('UTC'));
        self::assertFalse($this->window->isOpen($at1));

        // 09:00:00 UTC = 06:00:00 in Sao Paulo -> open
        $at2 = new DateTimeImmutable('2026-10-28 09:00:00', new \DateTimeZone('UTC'));
        self::assertTrue($this->window->isOpen($at2));
    }
}
