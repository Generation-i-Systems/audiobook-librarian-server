<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\WorkflowMessagingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowMessagingServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function jobMethodsCreateFetchCountAndDeleteJobs(): void
    {
        $service = new WorkflowMessagingService();

        $this->assertTrue($service->createJob(['user_id' => 1, 'content' => 'Import queue refresh']));
        $this->assertSame(1, $service->getJobCount());

        $jobs = $service->getJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('Import queue refresh', $jobs[0]['data']['content']);

        $job = $service->getJob((string) $jobs[0]['id']);
        $this->assertSame('Import queue refresh', $job['payload']['content']);

        $this->assertTrue($service->deleteJob((string) $jobs[0]['id']));
        $this->assertSame(0, $service->getJobCount());
    }

    #[Test]
    public function messageMethodsCreateListAndAcknowledgeMessages(): void
    {
        $service = new WorkflowMessagingService();
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $messageId = $service->createMessage([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'content' => 'A new book matched your follows.',
        ]);

        $this->assertNotNull($messageId);
        $this->assertCount(2, $service->getUsersForMessaging());

        $messages = $service->getMessages((string) $recipient->id, false, 10);
        $this->assertCount(1, $messages);
        $this->assertSame('A new book matched your follows.', $messages[0]['content']);

        $this->assertTrue($service->acknowledgeMessage((string) $messageId));
        $this->assertSame([], $service->getMessages((string) $recipient->id, false, 10));
        $this->assertCount(1, $service->getMessages((string) $recipient->id, true, 10));
    }

    #[Test]
    public function followMethodsInsertAndDeleteFollowRows(): void
    {
        $this->ensureFollowsTableExists();
        $service = new WorkflowMessagingService();
        $user = User::factory()->create();

        $this->assertTrue($service->createFollow((string) $user->id, 'author', '42'));
        $this->assertDatabaseHas('follows', [
            'user_id' => $user->id,
            'followable_type' => 'author',
            'followable_id' => 42,
        ]);

        $this->assertTrue($service->deleteFollow((string) $user->id, 'author', '42'));
        $this->assertDatabaseMissing('follows', [
            'user_id' => $user->id,
            'followable_type' => 'author',
            'followable_id' => 42,
        ]);
    }

    private function ensureFollowsTableExists(): void
    {
        if (Schema::hasTable('follows')) {
            return;
        }

        Schema::create('follows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('followable_type');
            $table->unsignedBigInteger('followable_id');
            $table->timestamps();
        });
    }
}
