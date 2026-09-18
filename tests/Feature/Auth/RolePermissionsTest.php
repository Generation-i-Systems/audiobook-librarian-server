<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionKey;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function testRolesAreSeededForEveryLiveRoleString(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'user',
                'library-user',
                'librivox-user',
                'hybrid-user',
                'admin',
                'super-admin',
                'unverified',
                'disabled',
            ],
            Role::query()->pluck('key')->all()
        );
    }

    public function testStandardUserRolesImplyLibraryManagementPermissions(): void
    {
        foreach (['user', 'library-user', 'hybrid-user'] as $roleKey) {
            $user = User::factory()->create(['role' => $roleKey]);

            foreach (Role::STANDARD_USER_PERMISSIONS as $permission) {
                $this->assertTrue(
                    $user->hasPermission($permission),
                    "Role [{$roleKey}] should imply [{$permission->value}]."
                );
            }

            $this->assertFalse(
                $user->hasPermission(PermissionKey::MANAGE_USERS),
                "Role [{$roleKey}] must not imply manage-users."
            );
            $this->assertFalse(
                $user->hasPermission(PermissionKey::ACCESS_ADMINER),
                "Role [{$roleKey}] must not imply access-adminer."
            );
        }
    }

    public function testLifecycleRolesImplyNoPermissions(): void
    {
        foreach (['unverified', 'disabled'] as $roleKey) {
            $user = User::factory()->create(['role' => $roleKey]);

            foreach (PermissionKey::cases() as $permission) {
                $this->assertFalse(
                    $user->hasPermission($permission),
                    "Role [{$roleKey}] should imply no permissions, got [{$permission->value}]."
                );
            }
        }
    }

    public function testLibrivoxUserRoleIsPureListenerWithoutManagementPermissions(): void
    {
        $user = User::factory()->create(['role' => 'librivox-user']);

        foreach (PermissionKey::cases() as $permission) {
            $this->assertFalse($user->hasPermission($permission));
        }
    }

    public function testUnknownRoleStringImpliesNoPermissions(): void
    {
        $user = User::factory()->create(['role' => 'mystery-role']);

        $this->assertNull($user->authRole);
        $this->assertFalse($user->hasPermission(PermissionKey::MANAGE_BOOKS));
    }

    public function testAdminRolesAreSeededWithEveryPermission(): void
    {
        foreach (['admin', 'super-admin'] as $roleKey) {
            $this->assertCount(
                count(PermissionKey::cases()),
                Role::query()->where('key', $roleKey)->firstOrFail()->permissions,
                "Role [{$roleKey}] should be seeded with every permission."
            );
        }
    }

    public function testAdminsBypassPermissionChecksRegardlessOfGrants(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (PermissionKey::cases() as $permission) {
            $this->assertTrue($admin->hasPermission($permission));
        }
    }

    public function testDirectGrantAddsPermissionsOnTopOfRoleBundle(): void
    {
        $user = User::factory()->create(['role' => 'unverified']);
        $this->assertFalse($user->hasPermission(PermissionKey::MANAGE_BOOKS));

        $user->permissions()->attach(
            Permission::query()->where('key', PermissionKey::MANAGE_BOOKS->value)->firstOrFail()
        );

        $this->assertTrue($user->hasPermission(PermissionKey::MANAGE_BOOKS));
        $this->assertFalse($user->hasPermission(PermissionKey::MANAGE_USERS));
    }

    public function testPermissionMiddlewareAllowsStandardRolesWithoutDirectGrants(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->get(route('authors.create'))->assertOk();
        $this->get(route('tags.index'))->assertOk();

        $this->actingAs(User::factory()->create(['role' => 'library-user']));

        $this->get(route('admin.books.create'))->assertOk();
    }

    public function testPermissionMiddlewareStillDeniesPermissionLessRoles(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $this->get(route('admin.books.create'))->assertForbidden();
        $this->post(route('authors.store'), ['name' => 'Blocked Author'])->assertForbidden();
        $this->assertDatabaseMissing('authors', ['name' => 'Blocked Author']);
    }

    public function testGatesResolveThroughRoleBundles(): void
    {
        $user = User::factory()->create(['role' => 'hybrid-user']);

        $this->assertTrue($user->can(PermissionKey::MANAGE_BOOKS->value));
        $this->assertTrue($user->can(PermissionKey::MANAGE_BADGES->value));
        $this->assertFalse($user->can(PermissionKey::MANAGE_QUEUE->value));
    }

    public function testUserEndpointExposesRolePermissions(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('role', 'library-user');

        $permissions = $response->json('permissions');
        $this->assertContains(PermissionKey::MANAGE_BOOKS->value, $permissions);
        $this->assertNotContains(PermissionKey::MANAGE_USERS->value, $permissions);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('role', 'library-user')
            ->assertJsonPath('name', $user->name);
    }

    public function testWebAdminUserUpdateRejectsUnknownRole(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $target = User::factory()->create(['role' => 'user']);

        $response = $this->put('/admin/users/' . $target->id, [
            'name' => $target->name,
            'username' => $target->username,
            'email' => $target->email,
            'role' => 'not-a-role',
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertSame('user', $target->fresh()->role);
    }

    public function testApiAdminUserCreationRejectsUnknownRole(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Bogus Role',
            'email' => 'bogus@example.com',
            'username' => 'bogus-role',
            'role' => 'not-a-role',
        ])->assertStatus(422);
    }

    public function testAdminUserEditPageAnnotatesRoleGrantedPermissions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $target = User::factory()->create(['role' => 'library-user']);

        $this->get('/admin/users/' . $target->id . '/edit')
            ->assertOk()
            ->assertSee('from role')
            ->assertSee('Library User (Local Books)');
    }
}
