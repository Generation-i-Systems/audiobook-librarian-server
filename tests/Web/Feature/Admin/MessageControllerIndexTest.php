<?php

declare(strict_types=1);

namespace Tests\Web\Feature\Admin;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageControllerIndexTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $otherUser;
    protected string $adminMessage = 'Message for the admin';
    protected string $otherMessage = 'Message for someone else';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
        $this->otherUser = User::factory()->create(['name' => 'Olly Other']);

        Message::create(['recipient_id' => $this->admin->id, 'content' => $this->adminMessage, 'type' => 'general']);
        Message::create(['recipient_id' => $this->otherUser->id, 'content' => $this->otherMessage, 'type' => 'general']);
    }

    /**
     * @return array<string, array{0: ?string, 1: bool, 2: bool}>
     */
    public static function recipientFilterProvider(): array
    {
        return [
            'defaults to current user' => [null, true, false],
            'selected other user' => ['other', false, true],
            'all users' => ['all', true, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recipientFilterProvider')]
    public function testIndexFiltersMessagesByRecipient(?string $recipient, bool $showsAdmin, bool $showsOther): void
    {
        $recipientParam = $recipient === 'other' ? (string) $this->otherUser->id : $recipient;
        $query = $recipientParam === null ? [] : ['recipient' => $recipientParam];

        $html = $this->actingAs($this->admin)
            ->get(route('admin.messages.index', $query))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            [$showsAdmin, $showsOther],
            [str_contains($html, $this->adminMessage), str_contains($html, $this->otherMessage)]
        );
    }

    public function testIndexShowsRecipientNameAndPreselectsFilter(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.messages.index', ['recipient' => $this->otherUser->id]));

        $response->assertOk()
            ->assertSeeInOrder(['<th>To</th>', $this->otherMessage, 'Olly Other'], false)
            ->assertSee('<option value="' . $this->otherUser->id . '" selected>', false);
    }
}
