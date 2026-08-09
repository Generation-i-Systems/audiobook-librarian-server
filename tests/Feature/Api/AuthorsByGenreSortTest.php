<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;

class AuthorsByGenreSortTest extends ApiTestCase
{
    public function testDefaultsToAlphabeticalOrder(): void
    {
        $genre = Genre::factory()->create();

        $bookB = Book::factory()->create(['directory_exists' => true]);
        $bookB->genres()->attach($genre->id);
        $authorB = Author::factory()->create(['name' => 'Bravo Author']);
        $bookB->authors()->attach($authorB->id);

        $bookA = Book::factory()->create(['directory_exists' => true]);
        $bookA->genres()->attach($genre->id);
        $authorA = Author::factory()->create(['name' => 'Alpha Author']);
        $bookA->authors()->attach($authorA->id);

        $response = $this->getJson("/api/v1/genres/{$genre->id}/authors");

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Alpha Author', 'Bravo Author'], $names);
    }

    public function testRandomSortReturnsTheSameSetOfAuthors(): void
    {
        $genre = Genre::factory()->create();

        $book = Book::factory()->create(['directory_exists' => true]);
        $book->genres()->attach($genre->id);
        $author = Author::factory()->create(['name' => 'Solo Author']);
        $book->authors()->attach($author->id);

        $response = $this->getJson("/api/v1/genres/{$genre->id}/authors?sort=random");

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Solo Author'], $names);
    }
}
