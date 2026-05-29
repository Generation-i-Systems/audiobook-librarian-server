<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Models\Book;
use App\Services\PermissionsQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(\App\Http\Controllers\Admin\TrashController::class)]
class TrashControllerRestoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function testRestoreFromWebRestoresDatabaseRecordAndQueuesPermissions(): void
    {
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        $booksDisk = Storage::fake('books');
        $trashDisk = Storage::fake('trash');

        $queueFile = storage_path('app/permissions-queue.txt');
        if (!is_dir(dirname($queueFile))) {
            mkdir(dirname($queueFile), 0775, true);
        }
        file_put_contents($queueFile, "# test\n");

        $user = new DocumentstoreUser([
            'id' => 'test-admin-user',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'is_admin' => true,
            'permissions' => ['admin.trash.*'],
        ]);

        Auth::login($user);
        $this->actingAs($user);
        $this->withoutMiddleware([
            \App\Http\Middleware\CheckAdminRole::class,
            \App\Http\Middleware\ResolveLibraryProfileFromHost::class,
        ]);

        $bookId = 123;
        $directoryPath = 'Science Fiction/Test Author/Test Series/01 Test Book';

        $book = Book::factory()->create([
            'id' => $bookId,
            'title' => 'Test Book',
            'directory_path' => $directoryPath,
        ]);

        $book->delete();

        $trashItemId = '2026-02-07_000000_' . $bookId;

        $trashDisk->put($trashItemId . '/book.json', json_encode([
            'id' => $bookId,
            'title' => 'Test Book',
            'directoryPath' => $directoryPath,
        ], JSON_PRETTY_PRINT));

        $trashDisk->put($trashItemId . '/metadata.json', json_encode([
            'type' => 'deletion',
            'files_moved' => true,
        ], JSON_PRETTY_PRINT));

        $trashDisk->put($trashItemId . '/files/test.mp3', 'audio');

        $response = $this->post(route('admin.trash.restore', ['id' => $trashItemId]));

        $response->assertRedirect(route('admin.trash.index'));

        $restored = Book::withTrashed()->find($bookId);
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
        $this->assertSame($directoryPath, $restored->directory_path);

        $booksDisk->assertExists($directoryPath . '/test.mp3');
        $trashDisk->assertMissing($trashItemId . '/book.json');

        $permissionsQueue = new PermissionsQueueService();
        $this->assertTrue($permissionsQueue->isInQueue($booksDisk->path($directoryPath)));
    }
}
