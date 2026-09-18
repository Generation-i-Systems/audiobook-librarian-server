<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\PermissionKey;

interface PermissionGroup
{
    public function hasPermission(PermissionKey|string $key): bool;
}
