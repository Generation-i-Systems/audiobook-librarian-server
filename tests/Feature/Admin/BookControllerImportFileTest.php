<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Auth\DocumentstoreUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class BookControllerImportFileTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    #[\PHPUnit\Framework\Attributes\Test]
    public function import_file_route_renders_view_for_admin()
    {
        $admin = new DocumentstoreUser(['id' => 'admin-user', 'role' => 'admin']);

        $this->mock(\App\Contracts\DocumentStoreServiceInterface::class, function ($mock) {
            $mock->shouldReceive('isAdmin')->with('admin-user')->andReturn(true);
        });

        $response = $this->actingAs($admin)->get('/admin/books/import-file');
        $response->assertStatus(200);
        $response->assertViewIs('admin.books.import_file');
        $response->assertSee('Import Book from File or Audio');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function import_file_route_redirects_for_non_admin()
    {
        $user = new DocumentstoreUser(['id' => 'non-admin-user', 'role' => 'user']);

        $this->mock(\App\Contracts\DocumentStoreServiceInterface::class, function ($mock) {
            $mock->shouldReceive('isAdmin')->with('non-admin-user')->andReturn(false);
        });

        $response = $this->actingAs($user)->get('/admin/books/import-file');
        $response->assertStatus(403);
    }
}
