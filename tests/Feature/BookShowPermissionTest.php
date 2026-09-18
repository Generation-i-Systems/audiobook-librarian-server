<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Enums\PermissionKey;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookShowPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected $mockService;
    protected array $testBook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testBook = [
            'id' => 'test-book-1',
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'authors' => ['Test Author'],
            'description' => 'Test description',
            'cover' => 'test-cover.jpg',
            'cover_image' => 'test-cover.jpg',
            'dateAdded' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];

        $this->mockService = \Mockery::mock(DocumentStoreServiceInterface::class);
        $this->mockService->shouldReceive('listBooks')->with(1, 100)->andReturn([
            'data' => [$this->testBook],
            'total' => 1,
        ]);
        $this->mockService->shouldReceive('getBook')->with('test-book-1')->andReturn($this->testBook);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->mockService);
    }

    public function test_unverified_user_does_not_see_edit_book_button(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unverified']));

        $response = $this->get(route('books.show', ['book' => 'test-book-1']));

        $response->assertOk();
        $response->assertDontSee('Edit Book');
    }

    public function test_user_with_manage_books_permission_sees_edit_book_button(): void
    {
        $user = User::factory()->create(['role' => 'library-user']);
        $user->permissions()->attach(Permission::where('key', PermissionKey::MANAGE_BOOKS->value)->firstOrFail());

        $this->actingAs($user);
        $response = $this->get(route('books.show', ['book' => 'test-book-1']));

        $response->assertOk();
        $response->assertSee('Edit Book');
    }

    public function test_admin_url_redirects_to_the_shared_show_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $userUrlResponse = $this->get(route('books.show', ['book' => 'test-book-1']));
        $adminUrlResponse = $this->get(route('admin.books.show', ['book' => 'test-book-1']));

        $userUrlResponse->assertOk();
        $userUrlResponse->assertSee('Edit Book');
        $adminUrlResponse->assertRedirect(route('books.show', ['book' => 'test-book-1']));
    }
}
