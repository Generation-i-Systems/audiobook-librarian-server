<?php

namespace Tests\Web\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookControllerPlannedActionsTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function plannedActionsReturnsMoveFilesWhenOldHasFilesAndNewEmpty(): void
    {
        Storage::disk('books')->put('OldDir/book.m4b', 'data');

        $mock = $this->createStub(DocumentStoreServiceInterface::class);
        $mock->method('isAdmin')->willReturn(true);
        $mock->method('getBook')->willReturn([
            'id' => 'book-1',
            'directoryPath' => 'OldDir',
        ]);
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);

        $admin = new DocumentstoreUser([
            'id' => 'test-admin-user',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        $this->actingAs($admin);

        $response = $this->postJson(route('admin.books.plannedActions', ['id' => 'book-1']), [
            'directoryPath' => 'NewDir',
            'coverImageUrl' => '',
            'audibleCoverImageUrl' => '',
        ]);

        $response->assertOk();
        $response->assertJsonPath('actions.0.type', 'move_files');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function plannedActionsIncludesDownloadCoverWhenCoverUrlAndDirectoryPresent(): void
    {
        $mock = $this->createStub(DocumentStoreServiceInterface::class);
        $mock->method('isAdmin')->willReturn(true);
        $mock->method('getBook')->willReturn([
            'id' => 'book-2',
            'directoryPath' => 'Dir2',
        ]);
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);

        $admin = new DocumentstoreUser([
            'id' => 'test-admin-user',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        $this->actingAs($admin);

        $response = $this->postJson(route('admin.books.plannedActions', ['id' => 'book-2']), [
            'directoryPath' => 'Dir2',
            'coverImageUrl' => 'https://example.com/cover.jpg',
            'audibleCoverImageUrl' => '',
        ]);

        $response->assertOk();
        $response->assertJsonFragment([
            'type' => 'download_cover',
        ]);
    }
}
