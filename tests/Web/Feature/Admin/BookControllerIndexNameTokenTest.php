<?php

declare(strict_types=1);

namespace Tests\Web\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookControllerIndexNameTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        $user = new DocumentstoreUser([
            'id' => 'test-admin-user',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'is_admin' => true,
            'permissions' => ['admin.books.*'],
        ]);

        Auth::login($user);
        $this->actingAs($user);
        $this->withoutMiddleware(\App\Http\Middleware\CheckAdminRole::class);
    }

    #[Test]
    public function indexSupportsGenreNameToken(): void
    {
        $genre = Genre::factory()->create(['name' => 'Fantasy']);
        $matching = Book::factory()->create(['title' => 'Mistborn']);
        $matching->genres()->attach($genre->id);
        Book::factory()->create(['title' => 'Other Book']);

        $response = $this->get('/admin/books?search=' . urlencode('genre:Fantasy'));

        $response->assertOk();
        $response->assertSee('Mistborn');
        $response->assertDontSee('Other Book');
    }
}
