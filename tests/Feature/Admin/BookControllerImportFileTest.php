<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use App\Services\FirestoreService;
use Illuminate\Support\Facades\App;

class BookControllerImportFileTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /** @test */
    public function importFile_route_renders_view_for_admin()
    {
        $userId = 'admin-user-id';
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('id')->andReturn($userId);
        $firestoreMock = $this->mock(FirestoreService::class);
        $firestoreMock->shouldReceive('isAdmin')->with($userId)->andReturn(true);
        $response = $this->get(route('admin.books.importFile'));
        $response->assertStatus(200);
        $response->assertViewIs('admin.books.import_file');
        $response->assertSee('Import Book from File or Audio');
    }

    /** @test */
    public function importFile_route_redirects_for_non_admin()
    {
        $userId = 'non-admin-user-id';
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('id')->andReturn($userId);
        $firestoreMock = $this->mock(FirestoreService::class);
        $firestoreMock->shouldReceive('isAdmin')->with($userId)->andReturn(false);
        $response = $this->get(route('admin.books.importFile'));
        $response->assertStatus(403);
    }
}
