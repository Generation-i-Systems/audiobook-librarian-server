<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Services\CompositeAuthorNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompositeAuthorNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function testItSplitsACompositeAuthorAndPreservesEveryBookLink(): void
    {
        $composite = Author::query()->create(['name' => 'Jane Doe & John Smith']);
        $book = Book::factory()->create();
        $book->authors()->attach($composite);

        $result = app(CompositeAuthorNormalizer::class)->normalize($composite);

        $this->assertSame('split', $result['action']);
        $this->assertSoftDeleted('authors', ['id' => $composite->id]);
        $this->assertSame(['Jane Doe', 'John Smith'], $book->fresh()->authors->pluck('name')->sort()->values()->all());
    }

    public function testItSoftDeletesACompositeAuthorWithNoActiveBooks(): void
    {
        $composite = Author::query()->create(['name' => 'Jane Doe & John Smith']);

        $result = app(CompositeAuthorNormalizer::class)->normalize($composite);

        $this->assertSame('deleted', $result['action']);
        $this->assertSoftDeleted('authors', ['id' => $composite->id]);
    }

    public function testItSkipsKnownAmbiguousCompositeNamesWithBooks(): void
    {
        $composite = Author::query()->create(['name' => 'Critical Role and Various']);
        $book = Book::factory()->create();
        $book->authors()->attach($composite);

        $result = app(CompositeAuthorNormalizer::class)->normalize($composite);

        $this->assertSame('skipped', $result['action']);
        $this->assertDatabaseHas('authors', ['id' => $composite->id, 'deleted_at' => null]);
        $this->assertSame([$composite->id], $book->fresh()->authors->pluck('id')->all());
    }

    public function testItOmitsPeopleWhoseOnlyCreditIsEditorTranslatorContributorOrForeword(): void
    {
        $composite = Author::query()->create([
            'name' => 'Jane Doe - editor, John Smith - translator, Mary Jones - contributor, Alex Roe - foreword, Pat Lee',
        ]);
        $book = Book::factory()->create();
        $book->authors()->attach($composite);

        $result = app(CompositeAuthorNormalizer::class)->normalize($composite);

        $this->assertSame('split', $result['action']);
        $this->assertSame(['Pat Lee'], $result['names']);
        $this->assertSame(['Pat Lee'], $book->fresh()->authors->pluck('name')->all());
    }

    public function testItRetainsOtherCreditedPeopleWithoutAddingTheirCreditToTheAuthorName(): void
    {
        $composite = Author::query()->create([
            'name' => 'Jane Doe, Neil Armstrong - introduction by, Joe White - adaptation',
        ]);
        $book = Book::factory()->create();
        $book->authors()->attach($composite);

        $result = app(CompositeAuthorNormalizer::class)->normalize($composite);

        $this->assertSame(['Jane Doe', 'Neil Armstrong', 'Joe White'], $result['names']);
        $this->assertSame(
            ['Jane Doe', 'Joe White', 'Neil Armstrong'],
            $book->fresh()->authors->pluck('name')->sort()->values()->all()
        );
    }
}
