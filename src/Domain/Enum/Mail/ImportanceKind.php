<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * The high-priority bucket an incoming email falls into.
 *
 * Persisted in the important_mail table and sent in the Telegram alerts.
 */
enum ImportanceKind: string
{
    case Government = 'government';
    case Bill       = 'bill';
    case Family     = 'family';

    public function labelPtBr(): string
    {
        return match ($this) {
            self::Government => 'Governo',
            self::Bill       => 'Conta / Fatura',
            self::Family     => 'Família',
        };
    }
}
