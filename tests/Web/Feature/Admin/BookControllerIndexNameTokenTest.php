<?php

declare(strict_types=1);

namespace Tests\Web\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Models\Book;
use App\Models\Genre;
use App\Services\Search\SemanticBookSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
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

    #[Test]
    public function indexSupportsSemanticFlag(): void
    {
        $first = Book::factory()->create(['title' => 'Alpha Result']);
        $second = Book::factory()->create(['title' => 'Beta Result']);
        Book::factory()->create(['title' => 'Not Ranked']);

        $semanticService = Mockery::mock(SemanticBookSearchService::class);
        $semanticService->shouldReceive('rankedBookIds')
            ->once()
            ->with('space adventure')
            ->andReturn([$second->id, $first->id]);
        $this->app->instance(SemanticBookSearchService::class, $semanticService);

        $response = $this->get(
            '/admin/books?search=' . urlencode('space adventure') . '&semantic=true'
        );

        $response->assertOk();
        $content = $response->getContent();
        $this->assertLessThan(
            strpos($content, 'Alpha Result'),
            strpos($content, 'Beta Result'),
            'Expected Beta Result (ranked first) to appear before Alpha Result in the response'
        );
        $response->assertDontSee('Not Ranked');
    }
}
