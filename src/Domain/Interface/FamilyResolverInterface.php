<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Entity\User\User;

interface FamilyResolverInterface
{
    /**
     * @return array<string, true>
     */
    public function emailsFor(User $user): array;

    public function hasAnyFamilyContact(User $user): bool;
}
