<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Services\BookIdResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookIdResolverTest extends TestCase
{
    use RefreshDatabase;

    public function testReturnsTheIdAsIsWhenItAlreadyExists(): void
    {
        $book = Book::factory()->create();
        $resolver = new BookIdResolver();

        $this->assertSame($book->id, $resolver->resolve($book->id, null, null, null));
    }

    public function testResolvesViaTrailingIdSuffixInBookPath(): void
    {
        $book = Book::factory()->create([
            'directory_path' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle",
        ]);
        $resolver = new BookIdResolver();

        $resolved = $resolver->resolve(999999999, "Jeffery H. Haskell/Grimm's War/09 The Longest Battle_{$book->id}", null, null);

        $this->assertSame($book->id, $resolved);
    }

    public function testResolvesViaDirectoryPathMatchWhenNoIdSuffix(): void
    {
        $book = Book::factory()->create([
            'directory_path' => 'Some Author/Some Series/01 Some Book Title',
        ]);
        $resolver = new BookIdResolver();

        $resolved = $resolver->resolve(999999998, 'Some Author/Some Series/01 Some Book Title', null, null);

        $this->assertSame($book->id, $resolved);
    }

    public function testResolvesViaTitleAndAuthorWhenPathDoesNotMatchAnything(): void
    {
        $author = Author::factory()->create(['name' => 'Some Author']);
        $book = Book::factory()->create([
            'title' => 'Some Unique Title',
            'directory_path' => 'Completely/Unrelated/Path',
        ]);
        $book->authors()->attach($author->id);
        $resolver = new BookIdResolver();

        $resolved = $resolver->resolve(999999997, '/storage/emulated/0/Audiobooks/Some Unique Title', 'Some Unique Title', 'Some Author');

        $this->assertSame($book->id, $resolved);
    }

    public function testReturnsNullWhenNothingResolves(): void
    {
        $resolver = new BookIdResolver();

        $resolved = $resolver->resolve(999999996, 'Nothing/Matches/This', 'No Such Title', 'No Such Author');

        $this->assertNull($resolved);
    }

    public function testReturnsNullWhenNoBookPathOrFallbackProvided(): void
    {
        $resolver = new BookIdResolver();

        $this->assertNull($resolver->resolve(999999995, null, null, null));
    }
}
