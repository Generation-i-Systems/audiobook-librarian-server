<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParsePathControllerTest extends TestCase
{
    use RefreshDatabase;
    public function test_parse_path_returns_series_number_without_leading_zeros(): void
    {
        /** @var User $user */
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);

        $response = $this->postJson('/admin/books/parse-path', [
            'path' => 'Fantasy/Brandon Sanderson/The Stormlight Archive/01 The Way of Kings',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'author' => 'Brandon Sanderson',
            'title' => 'The Way of Kings',
            'genre' => 'Fantasy',
            'series' => 'The Stormlight Archive',
            'seriesNumber' => '1',
        ]);
    }
}
