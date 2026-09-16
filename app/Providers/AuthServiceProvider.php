<?php

namespace App\Providers;

use App\Contracts\Permissible;
use App\Enums\PermissionKey;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];


    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Admins/super-admins implicitly pass every permission check.
        Gate::before(fn (Permissible $user) => $user->isAdmin() ? true : null);

        foreach (PermissionKey::cases() as $permissionKey) {
            Gate::define($permissionKey->value, fn (Permissible $user) => $user->hasPermission($permissionKey));
        }
    }
}
