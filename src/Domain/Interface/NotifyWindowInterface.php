<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use DateTimeImmutable;

interface NotifyWindowInterface
{
    public function isOpen(DateTimeImmutable $at): bool;
}
