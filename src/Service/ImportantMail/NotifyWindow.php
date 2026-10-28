<?php

declare(strict_types=1);

namespace App\Service\ImportantMail;

use App\Domain\Interface\NotifyWindowInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Evaluates whether an alert timestamp falls within the allowed immediate
 * notification window (06:00:00 to 20:00:00) in the configured timezone.
 *
 * Messages received outside this window are recorded as pending and included
 * in the 06:00 morning digest.
 */
final readonly class NotifyWindow implements NotifyWindowInterface
{
    private DateTimeZone $tz;

    public function __construct(string $timezone = 'America/Sao_Paulo')
    {
        $this->tz = new DateTimeZone($timezone);
    }

    public function isOpen(DateTimeImmutable $at): bool
    {
        $local = $at->setTimezone($this->tz);

        $hour   = (int) $local->format('G'); // 0-23
        $minute = (int) $local->format('i');
        $second = (int) $local->format('s');

        $secondsSinceMidnight = $hour * 3600 + $minute * 60 + $second;

        $start = 6 * 3600;  // 06:00:00 inclusive
        $end   = 20 * 3600; // 20:00:00 exclusive

        return $secondsSinceMidnight >= $start && $secondsSinceMidnight < $end;
    }
}
