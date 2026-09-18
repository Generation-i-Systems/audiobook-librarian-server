<?php

use App\Enums\PermissionKey;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $allPermissionIds = Permission::query()->pluck('id')->all();

        foreach (Role::ROLE_PERMISSIONS as $roleKey => $rolePermissions) {
            $role = Role::query()->updateOrCreate(
                ['key' => $roleKey],
                ['label' => Role::ROLE_LABELS[$roleKey]]
            );

            $permissionIds = $rolePermissions === null
                ? $allPermissionIds
                : Permission::query()
                    ->whereIn('key', array_map(
                        fn (PermissionKey $permissionKey) => $permissionKey->value,
                        $rolePermissions
                    ))
                    ->pluck('id')
                    ->all();

            $role->permissions()->sync($permissionIds);
        }
    }

    public function down(): void
    {
        $roleIds = Role::query()->whereIn('key', Role::keyList())->pluck('id')->all();

        \Illuminate\Support\Facades\DB::table('role_permission')
            ->whereIn('role_id', $roleIds)
            ->delete();
        Role::query()->whereIn('key', Role::keyList())->delete();
    }
};
