<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\Enum\Mail\ImportanceKind;

interface ModelClassifierInterface
{
    public function isEnabled(): bool;

    public function classify(string $fromAddress, ?string $subject, ?string $bodyText): ?ImportanceKind;
}
