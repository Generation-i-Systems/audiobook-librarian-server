<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function userLookupAndCredentialMethodsReturnExpectedResults(): void
    {
        $service = new UserAccountService();
        $user = User::factory()->create([
            'email' => 'reader@example.com',
            'username' => 'reader',
            'password' => Hash::make('secret-pass'),
            'role' => 'admin',
            'apple_id' => 'apple-123',
            'discord_id' => 'discord-123',
            'google_id' => 'google-123',
        ]);

        $byId = $service->getUserById($user->id);
        $this->assertSame('reader@example.com', $byId['email']);
        $this->assertSame('google-123', $byId['google_id']);

        $this->assertSame((string) $user->id, (string) ($service->getUserByCredentials([
            'email' => 'reader@example.com',
            'password' => 'secret-pass',
        ])['id'] ?? null));

        $this->assertSame((string) $user->id, (string) ($service->getUserByCredentials([
            'username' => 'reader',
            'password' => 'secret-pass',
        ])['id'] ?? null));

        $rememberUser = $service->getUserByRememberToken($user->id, $user->remember_token);
        $this->assertSame((string) $user->id, (string) ($rememberUser['id'] ?? null));

        $this->assertSame((string) $user->id, (string) ($service->getUserByEmail('reader@example.com')['id'] ?? null));
        $this->assertSame((string) $user->id, (string) ($service->getUserByUsername('reader')['id'] ?? null));
        $this->assertSame((string) $user->id, (string) ($service->getUserByAppleId('apple-123')['id'] ?? null));
        $this->assertSame((string) $user->id, (string) ($service->getUserByDiscordId('discord-123')['id'] ?? null));

        $this->assertTrue($service->userExistsByEmail('reader@example.com'));
        $this->assertTrue($service->userExistsByUsername('reader'));
        $this->assertTrue($service->validateUserCredentials($user->toArray(), ['password' => 'secret-pass']));
        $this->assertTrue($service->isAdmin((string) $user->id));
        $this->assertCount(1, $service->getAdminUsers());
    }

    #[Test]
    public function createUpdateDeleteAndRememberTokenMethodsManageUserLifecycle(): void
    {
        $service = new UserAccountService();

        $userId = $service->createUser([
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'plain-password',
        ]);

        $user = User::query()->findOrFail($userId);
        $this->assertSame('new', $user->username);
        $this->assertTrue(Hash::check('plain-password', $user->password));

        $updatedUser = $service->updateUser((string) $userId, ['name' => 'Updated User']);
        $this->assertSame('Updated User', $updatedUser->name);

        $service->updateRememberToken((string) $userId, 'fresh-token');
        $this->assertSame('fresh-token', User::query()->findOrFail($userId)->remember_token);

        $this->assertSame(1, $service->deleteUser((string) $userId));
        $this->assertSoftDeleted('users', ['id' => $userId]);
    }

    #[Test]
    public function accountRequestMethodsListFetchApproveAndRejectRequests(): void
    {
        $this->ensureAccountRequestsTableExists();
        $service = new UserAccountService();

        $pendingId = (string) DB::table('account_requests')->insertGetId([
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'username' => 'pendinguser',
            'password' => 'pending-password',
            'status' => 'pending',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $rejectId = (string) DB::table('account_requests')->insertGetId([
            'name' => 'Reject Me',
            'email' => 'reject@example.com',
            'username' => 'rejectme',
            'password' => 'reject-password',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pendingRequests = $service->getPendingAccountRequests();
        $this->assertCount(2, $pendingRequests);
        $this->assertSame($rejectId, (string) $pendingRequests[0]->id);

        $request = $service->getAccountRequest($pendingId);
        $this->assertSame('pending@example.com', $request['email']);

        $this->assertTrue($service->approveAccountRequest($pendingId));
        $this->assertDatabaseHas('account_requests', ['id' => $pendingId, 'status' => 'approved']);
        $this->assertDatabaseHas('users', ['email' => 'pending@example.com', 'username' => 'pendinguser']);

        $this->assertTrue($service->rejectAccountRequest($rejectId));
        $this->assertDatabaseHas('account_requests', ['id' => $rejectId, 'status' => 'rejected']);
    }

    private function ensureAccountRequestsTableExists(): void
    {
        if (Schema::hasTable('account_requests')) {
            return;
        }

        Schema::create('account_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('username');
            $table->string('password')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }
}
