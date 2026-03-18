<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\Message;
use App\Models\User;
use App\Services\AdminMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
