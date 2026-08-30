<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\BookTag;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookListSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_book_list_combines_genre_id_and_banned_tag_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $fantasy = Genre::factory()->create(['name' => 'Fantasy']);
        $safeFantasy = Book::factory()->create(['title' => 'Safe Fantasy']);
        $spicyFantasy = Book::factory()->create(['title' => 'Spicy Fantasy']);
        $safeOtherGenre = Book::factory()->create(['title' => 'Safe Other Genre']);

        $fantasy->books()->attach([$safeFantasy->id, $spicyFantasy->id]);
        BookTag::create([
            'book_id' => $spicyFantasy->id,
            'scope' => 'system',
            'owner_key' => 'system',
            'tags' => ['spicy'],
        ]);

        $response = $this->actingAs($admin)->get('/admin/books?search=' . urlencode('genreId:' . $fantasy->id . ' tag:-spicy'));

        $response->assertOk();
        $response->assertSee('Safe Fantasy');
        $response->assertDontSee('Spicy Fantasy');
        $response->assertDontSee('Safe Other Genre');
    }
}
