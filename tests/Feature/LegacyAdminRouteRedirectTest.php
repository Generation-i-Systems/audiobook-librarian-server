<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyAdminRouteRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function testLegacyGetRedirectPreservesTheFullQueryString(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->get('/admin/books?search=Lindsey+Sterling&sort=name&direction=asc&page=2');

        $response->assertRedirectContains('/books?');
        $this->assertSame('/books', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertEquals([
            'search' => 'Lindsey Sterling',
            'sort' => 'name',
            'direction' => 'asc',
            'page' => '2',
        ], $query);
    }

    public function testLegacyPostRedirectPreservesTheMethodAndQueryString(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->post('/admin/authors?return_url=%2Fauthors%3Fpage%3D2', [
            'name' => 'Redirected Author',
        ]);

        $response->assertStatus(307);
        $response->assertRedirectContains('/authors?');
        $this->assertSame('/authors', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['return_url' => '/authors?page=2'], $query);
    }
}
