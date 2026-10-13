<?php

declare(strict_types=1);

namespace Tests\Web\Feature\Admin;

use Carbon\Carbon;
use Tests\TestCase;

class MessagesIndexViewTest extends TestCase
{
    public function testShowsSenderNameAndRealMessageAgeFromCamelCaseMessageData(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $senderName = 'Pat Sender';
        $messages = [
            ['id' => 1, 'senderId' => 7, 'content' => 'Hello', 'acknowledgedAt' => null, 'createdAt' => '2026-10-12T12:00:00.000000Z'],
            ['id' => 2, 'senderId' => null, 'content' => 'Auto', 'acknowledgedAt' => '2026-10-13T12:00:00.000000Z', 'createdAt' => '2026-10-14T11:00:00.000000Z'],
        ];
        $users = [['id' => 7, 'name' => $senderName, 'email' => 'pat@example.test']];

        $html = view('admin.messages.index', compact('messages', 'users'))
            ->withErrors([])
            ->render();

        $this->assertStringContainsString($senderName, $html);
        $this->assertStringContainsString('2 days ago', $html);
        $this->assertStringContainsString('1 hour ago', $html);
        $this->assertStringContainsString('System', $html);
        $this->assertStringNotContainsString('0 seconds ago', $html);
        $this->assertSame(1, substr_count($html, 'badge bg-success">Acknowledged'));

        Carbon::setTestNow();
    }
}
