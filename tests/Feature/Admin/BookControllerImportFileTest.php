<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use App\Models\User;

class BookControllerImportFileTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /** @test */
    public function importFile_route_renders_view_for_admin()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Auth::login($admin);
        $response = $this->actingAs($admin)->get(route('admin.books.importFile'));
        $response->assertStatus(200);
        $response->assertViewIs('admin.books.import_file');
        $response->assertSee('Import Book from File or Audio');
    }

    /** @test */
    public function importFile_route_redirects_for_non_admin()
    {
        $user = User::factory()->create(['is_admin' => false]);
        Auth::login($user);
        $response = $this->actingAs($user)->get(route('admin.books.importFile'));
        $response->assertStatus(403);
    }
}
