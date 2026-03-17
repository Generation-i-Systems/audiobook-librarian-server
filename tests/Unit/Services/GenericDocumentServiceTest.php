<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Message;
use App\Models\User;
use App\Services\GenericDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenericDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function getDocumentReturnsMappedModelDataAndNullForUnknownCollection(): void
    {
        $service = new GenericDocumentService();
        $user = User::factory()->create(['email' => 'doc@example.com']);
        $author = Author::query()->create(['name' => 'Doc Author']);

        $userDoc = $service->getDocument('users', (string) $user->id);
        $authorDoc = $service->getDocument('authors', (string) $author->id);

        $this->assertSame('doc@example.com', $userDoc['email']);
        $this->assertSame('Doc Author', $authorDoc['name']);
        $this->assertNull($service->getDocument('unknown_collection', '1'));
    }

    #[Test]
    public function updateDocumentUpdatesMappedModelAndRejectsUnknownCollection(): void
    {
        $service = new GenericDocumentService();
        $book = Book::factory()->create(['title' => 'Before']);
        $message = Message::query()->create([
            'recipient_id' => User::factory()->create()->id,
            'content' => 'Before message',
        ]);

        $this->assertTrue($service->updateDocument('books', (string) $book->id, ['title' => 'After']));
        $this->assertTrue($service->updateDocument('messages', (string) $message->id, ['content' => 'After message']));

        $this->assertSame('After', Book::query()->findOrFail($book->id)->title);
        $this->assertSame('After message', Message::query()->findOrFail($message->id)->content);
        $this->assertFalse($service->updateDocument('unknown_collection', '1', ['foo' => 'bar']));
    }
}
