<?php

use App\Enums\PermissionKey;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Permission::query()->firstOrCreate(
            ['key' => PermissionKey::IMPORT_BOOKS->value],
            ['label' => PermissionKey::IMPORT_BOOKS->label()]
        );
    }

    public function down(): void
    {
        Permission::query()->where('key', PermissionKey::IMPORT_BOOKS->value)->delete();
    }
};
