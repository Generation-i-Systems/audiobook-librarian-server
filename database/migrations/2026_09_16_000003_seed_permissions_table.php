<?php

use App\Enums\PermissionKey;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        foreach (PermissionKey::cases() as $permissionKey) {
            Permission::query()->firstOrCreate(
                ['key' => $permissionKey->value],
                ['label' => $permissionKey->label()]
            );
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('key', array_map(
            fn (PermissionKey $permissionKey) => $permissionKey->value,
            PermissionKey::cases()
        ))->delete();
    }
};
