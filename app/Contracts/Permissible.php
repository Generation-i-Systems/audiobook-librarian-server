<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PermissionKey;

interface Permissible
{
    public function isAdmin(): bool;

    public function hasPermission(PermissionKey|string $key): bool;
}
