<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\Enum\Mail\ImportanceKind;
use App\Entity\Mail\Message;

interface TelegramNotifierInterface
{
    public function send(string $htmlText): void;

    public static function format(Message $message, ImportanceKind $kind): string;
}
