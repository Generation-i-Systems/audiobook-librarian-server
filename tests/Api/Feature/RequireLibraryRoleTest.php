<?php

declare(strict_types=1);

namespace Tests\Api\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RequireLibraryRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'library_profiles.profiles.librivox.hosts' => ['librivox.test'],
            'library_profiles.profiles.hybrid.hosts' => ['hybrid.test'],
        ]);
    }

    private function makeUser(string $role): array
    {
        $user = User::factory()->create(['role' => $role]);
        $token = $user->createToken('test')->plainTextToken;
        return [$user, ['Authorization' => 'Bearer ' . $token]];
    }

    private function getMe(array $headers, string $host = 'localhost'): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withHeaders($headers)
            ->getJson("http://{$host}/api/v1/me");
    }

    // All library roles are allowed regardless of host
    public function testLibraryUserCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('library-user');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    public function testLibrivoxUserCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('librivox-user');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    public function testLibrivoxUserApiGenresUseLibrivoxCatalogOnly(): void
    {
        [, $headers] = $this->makeUser('librivox-user');
        $this->seedMixedLocalAndLibrivoxCatalogs();

        $this->withHeaders($headers)
            ->getJson('http://localhost/api/v1/genres?language=English')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Librivox Genre');
    }

    public function testLibrivoxUserApiAuthorsUseLibrivoxCatalogOnly(): void
    {
        [, $headers] = $this->makeUser('librivox-user');
        $this->seedMixedLocalAndLibrivoxCatalogs();

        $this->withHeaders($headers)
            ->getJson('http://localhost/api/v1/authors?language=English')
            ->assertStatus(200)
            ->assertJsonPath('authors.0.name', 'Librivox Author')
            ->assertJsonPath('pagination.total', 1);
    }

    public function testLibrivoxUserApiBooksApplyEnglishLanguageFilter(): void
    {
        [, $headers] = $this->makeUser('librivox-user');
        $this->seedMixedLocalAndLibrivoxCatalogs();

        $this->withHeaders($headers)
            ->getJson('http://localhost/api/v1/books?enhanced=true&language=English')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'English Librivox Book')
            ->assertJsonPath('data.0.narrator.0', 'Librivox Reader')
            ->assertJsonPath('pagination.total', 1);
    }

    public function testHybridUserCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('hybrid-user');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    public function testAdminCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('admin');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    public function testSuperAdminCanAccessAnyHost(): void
    {
        [, $headers] = $this->makeUser('super-admin');
        $this->getMe($headers, 'localhost')->assertStatus(200);
        $this->getMe($headers, 'librivox.test')->assertStatus(200);
        $this->getMe($headers, 'hybrid.test')->assertStatus(200);
    }

    // Disallowed roles are blocked regardless of host
    public function testUnverifiedUserIsBlocked(): void
    {
        [, $headers] = $this->makeUser('unverified');
        $this->getMe($headers)->assertStatus(403);
    }

    public function testUserRoleIsBlocked(): void
    {
        [, $headers] = $this->makeUser('user');
        $this->getMe($headers)->assertStatus(403);
    }

    public function testUnauthenticatedRequestIsBlocked(): void
    {
        $this->getJson('http://localhost/api/v1/me')->assertStatus(401);
    }

    // X-Library-Profile header selects a profile without changing the host
    public function testXLibraryProfileHeaderSelectsProfile(): void
    {
        config([
            'library_profiles.profiles.librivox.source_mode' => 'librivox',
            'library_profiles.profiles.main.source_mode' => 'local',
        ]);

        [, $headers] = $this->makeUser('hybrid-user');

        $this->withHeaders(array_merge($headers, ['X-Library-Profile' => 'librivox']))
            ->getJson('http://localhost/api/v1/me')
            ->assertStatus(200);

        $this->withHeaders(array_merge($headers, ['X-Library-Profile' => 'main']))
            ->getJson('http://localhost/api/v1/me')
            ->assertStatus(200);
    }

    public function testXLibraryProfileHeaderWithUnknownProfileFallsBackToHost(): void
    {
        [, $headers] = $this->makeUser('hybrid-user');

        $this->withHeaders(array_merge($headers, ['X-Library-Profile' => 'nonexistent']))
            ->getJson('http://localhost/api/v1/me')
            ->assertStatus(200);
    }

    public function testXLibraryProfileHeaderIsIgnoredForFixedRoles(): void
    {
        config(['library_profiles.profiles.librivox.source_mode' => 'librivox']);

        // library-user always gets local source mode regardless of header
        [, $headers] = $this->makeUser('library-user');
        $this->withHeaders(array_merge($headers, ['X-Library-Profile' => 'librivox']))
            ->getJson('http://localhost/api/v1/me')
            ->assertStatus(200);
    }

    // Web requests: role sets source mode but does not gate access

    private function mockDocumentStore(): void
    {
        $mockStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockStore->shouldReceive('listBooks')->andReturn([
            'data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1,
        ]);
        $mockStore->shouldReceive('getUniqueValues')->andReturn([]);
        $this->app->instance(DocumentStoreServiceInterface::class, $mockStore);
    }

    private function seedMixedLocalAndLibrivoxCatalogs(): void
    {
        $localAuthor = Author::factory()->create(['name' => 'Local Author']);
        $localGenre = Genre::factory()->create(['name' => 'Local Genre']);
        $localBook = Book::factory()->create([
            'title' => 'Local Book',
            'needs_review' => false,
        ]);
        $localBook->authors()->attach($localAuthor->id);
        $localBook->genres()->attach($localGenre->id);

        $librivoxAuthorId = DB::table('librivox_authors')->insertGetId([
            'name' => 'Librivox Author',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $librivoxGenreId = DB::table('librivox_genres')->insertGetId([
            'name' => 'Librivox Genre',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $englishBookId = DB::table('librivox_books')->insertGetId([
            'librivox_id' => 'lv-en',
            'title' => 'English Librivox Book',
            'language' => 'English',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $spanishBookId = DB::table('librivox_books')->insertGetId([
            'librivox_id' => 'lv-es',
            'title' => 'Spanish Librivox Book',
            'language' => 'Spanish',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('librivox_book_author')->insert([
            ['book_id' => $englishBookId, 'author_id' => $librivoxAuthorId],
            ['book_id' => $spanishBookId, 'author_id' => $librivoxAuthorId],
        ]);
        DB::table('librivox_book_genre')->insert([
            ['book_id' => $englishBookId, 'genre_id' => $librivoxGenreId],
            ['book_id' => $spanishBookId, 'genre_id' => $librivoxGenreId],
        ]);
        DB::table('librivox_chapters')->insert([
            'book_id' => $englishBookId,
            'chapter_number' => 1,
            'title' => 'Chapter 1',
            'reader' => 'Librivox Reader',
            'file_name' => 'chapter-1.mp3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function testLibrivoxUserWebBooksRequestSetsLibrivoxSourceMode(): void
    {
        config([
            'library_profiles.profiles.main.source_mode' => 'local',
            'library_profiles.active_source_mode' => 'local',
        ]);

        $this->mockDocumentStore();

        [$user] = $this->makeUser('librivox-user');
        $this->actingAs($user)->get('http://localhost/books')->assertStatus(200);

        $this->assertEquals('librivox', config('library_profiles.active_source_mode'));
    }

    public function testLibrivoxUserJsonBooksEndpointSetsLibrivoxSourceMode(): void
    {
        config([
            'library_profiles.profiles.main.source_mode' => 'local',
            'library_profiles.active_source_mode' => 'local',
        ]);

        $mockStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockStore->shouldReceive('listBooks')->andReturn([
            'data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1,
        ]);
        $this->app->instance(DocumentStoreServiceInterface::class, $mockStore);

        [$user] = $this->makeUser('librivox-user');
        $this->actingAs($user)->getJson('http://localhost/api/books/json')->assertStatus(200);

        $this->assertEquals('librivox', config('library_profiles.active_source_mode'));
    }

    public function testLibrivoxUserJsonBooksEndpointForcesListView(): void
    {
        config([
            'library_profiles.profiles.main.source_mode' => 'local',
            'library_profiles.active_source_mode' => 'local',
        ]);

        $mockStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockStore->shouldReceive('listBooks')->once()->andReturn([
            'data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1,
        ]);
        $this->app->instance(DocumentStoreServiceInterface::class, $mockStore);

        [$user] = $this->makeUser('librivox-user');

        $this->actingAs($user)
            ->getJson('http://localhost/api/books/json?view_type=grid')
            ->assertStatus(200)
            ->assertJsonPath('view_type', 'list');
    }

    public function testJsonBooksEndpointAcceptsEmptySearchString(): void
    {
        config([
            'library_profiles.profiles.main.source_mode' => 'local',
            'library_profiles.active_source_mode' => 'local',
        ]);

        $mockStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockStore->shouldReceive('listBooks')->once()->andReturn([
            'data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1,
        ]);
        $this->app->instance(DocumentStoreServiceInterface::class, $mockStore);

        [$user] = $this->makeUser('library-user');

        $this->actingAs($user)
            ->getJson('http://localhost/api/books/json?search=')
            ->assertStatus(200);
    }

    public function testUserRoleWebBooksRequestIsNotBlocked(): void
    {
        $this->mockDocumentStore();

        [$user] = $this->makeUser('user');
        $this->actingAs($user)->get('http://localhost/books')->assertStatus(200);
    }

    // Root / redirect: library roles always go to books.index, not admin

    public function testLibrivoxUserWithIsAdminRedirectsToBookIndexNotAdmin(): void
    {
        $user = User::factory()->create(['role' => 'librivox-user', 'is_admin' => true]);
        $response = $this->actingAs($user)->get('http://localhost/');
        $response->assertRedirect(route('books.index'));
    }

    public function testLibraryUserWithIsAdminRedirectsToBookIndex(): void
    {
        $user = User::factory()->create(['role' => 'library-user', 'is_admin' => true]);
        $response = $this->actingAs($user)->get('http://localhost/');
        $response->assertRedirect(route('books.index'));
    }

    public function testAdminRoleWithIsAdminRedirectsToAdminBooks(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $response = $this->actingAs($user)->get('http://localhost/');
        $response->assertRedirect(route('admin.books.index'));
    }
}
