<?php

namespace Tests\Unit\Models;

use App\Models\Book;
use App\Models\Review;
use App\Models\Genre;


use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookTest extends TestCase
{
    use RefreshDatabase; // Reset the database after each test

    public function test_book_has_reviews_relationship()
    {
        $book = Book::factory()->create();
        $review = Review::factory()->create(['book_id' => $book->id]);

        $this->assertTrue($book->reviews->contains($review));
        $this->assertEquals(1, $book->reviews->count());
    }

    public function test_book_has_genre_relationship()
    {
        $book = Book::factory()->create();

        $this->assertInstanceOf(Genre::class, $book->genre);
    }
}
