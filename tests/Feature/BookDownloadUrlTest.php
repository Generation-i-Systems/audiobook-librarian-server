<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookDownloadUrlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function testDownloadUrlResponseHasNoDecorativeSignatureOrExpiry(): void
    {
        Storage::fake('books');
        Storage::disk('books')->put('Author/Book/chapter1.mp3', 'fake audio content');

        $mockStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockStore->shouldReceive('getBook')->with('42')->andReturn([
            'id' => 42,
            'title' => 'Test Book',
            'directoryPath' => 'Author/Book',
        ]);
        $this->app->instance(DocumentStoreServiceInterface::class, $mockStore);

        $user = User::factory()->create(['role' => 'library-user']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this
            ->withoutMiddleware(\App\Http\Middleware\ResolveLibraryProfileFromHost::class)
            ->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/books/42/download-url');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('expires_at');
        $body = $response->json();
        $this->assertStringNotContainsString('expires=', $body['files'][0]['download_url']);
        $this->assertStringNotContainsString('signature=', $body['files'][0]['download_url']);
    }
}
