<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\Message;
use App\Models\User;
use App\Services\AdminMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminMaintenanceServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function getAllUsersReturnsAdminFriendlyShape(): void
    {
        $service = new AdminMaintenanceService();

        User::factory()->create([
            'name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'google_id' => 'google-1',
        ]);

        $users = $service->getAllUsers();

        $this->assertCount(1, $users);
        $this->assertSame('Admin User', $users[0]['name']);
        $this->assertSame('google-1', $users[0]['google_id']);
        $this->assertSame('admin', $users[0]['role']);
    }

    #[Test]
    public function getAllUsersReturnsNullLastUsedAtWhenUserHasNeverLoggedInOrCalledApi(): void
    {
        $service = new AdminMaintenanceService();
        User::factory()->create(['last_login_at' => null]);

        $users = $service->getAllUsers();

        $this->assertNull($users[0]['last_used_at']);
    }

    #[Test]
    public function getAllUsersUsesWebLoginWhenMoreRecentThanApiUsage(): void
    {
        $service = new AdminMaintenanceService();
        $user = User::factory()->create(['last_login_at' => now()]);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test-token',
            'token' => hash('sha256', 'plain-text-token'),
            'last_used_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $users = $service->getAllUsers();

        $this->assertSame(
            \Carbon\Carbon::parse($user->last_login_at)->toDateTimeString(),
            \Carbon\Carbon::parse($users[0]['last_used_at'])->toDateTimeString()
        );
    }

    #[Test]
    public function getAllUsersUsesApiTokenUsageWhenMoreRecentThanWebLogin(): void
    {
        $service = new AdminMaintenanceService();
        $user = User::factory()->create(['last_login_at' => now()->subWeek()]);
        $recentApiUse = now();
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test-token',
            'token' => hash('sha256', 'plain-text-token'),
            'last_used_at' => $recentApiUse,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $users = $service->getAllUsers();

        $this->assertSame($recentApiUse->toDateTimeString(), \Carbon\Carbon::parse($users[0]['last_used_at'])->toDateTimeString());
    }

    #[Test]
    public function getAllUsersFallsBackToLegacyApiTokensLastUsedAt(): void
    {
        $service = new AdminMaintenanceService();
        $user = User::factory()->create(['last_login_at' => null]);
        $recentApiUse = now();
        DB::table('api_tokens')->insert([
            'user_id' => (string) $user->id,
            'token' => 'legacy-token-value',
            'last_used_at' => $recentApiUse,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $users = $service->getAllUsers();

        $this->assertSame($recentApiUse->toDateTimeString(), \Carbon\Carbon::parse($users[0]['last_used_at'])->toDateTimeString());
    }

    #[Test]
    public function deleteHelpersRemoveMessagesAndSoftDeleteBooks(): void
    {
        $service = new AdminMaintenanceService();
        $recipient = User::factory()->create();
        $message = Message::query()->create([
            'recipient_id' => $recipient->id,
            'content' => 'Admin notice',
        ]);
        $book = Book::factory()->create();

        $this->assertTrue($service->deleteMessage((string) $message->id));
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);

        $this->assertTrue($service->deleteBook((string) $book->id, false));
        $this->assertSoftDeleted('books', ['id' => $book->id]);
        $this->assertFalse($service->deleteBook('999999', false));
    }
}
