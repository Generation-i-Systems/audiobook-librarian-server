<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An ISBN alone must not make findExistingBook() reuse (and overwrite) a book with a
 * different title: ISBNs in the library can be wrong (e.g. one split book's ISBN saved
 * on another) and are not unique, and the source files can't be edited to fix them.
 */
class BookImportServiceFindExistingBookIsbnTest extends TestCase
{
    use RefreshDatabase;

    private const ISBN = '9781445892832';

    private BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookImportService(
            $this->app->make(GenreMappingService::class),
            $this->app->make(SourceTrashService::class)
        );
    }

    public static function matchingTitleProvider(): array
    {
        return [
            'same title' => ['By Royal Command', 'By Royal Command'],
            'case and punctuation' => ['By Royal Command', 'by royal command!'],
            'series prefix on incoming' => ['By Royal Command', 'Young Bond: By Royal Command'],
            'subtitle on existing' => ['By Royal Command: A Young Bond Novel', 'By Royal Command'],
            'no incoming title' => ['By Royal Command', ''],
        ];
    }

    #[Test]
    #[DataProvider('matchingTitleProvider')]
    public function reusesIsbnMatchWhenTitlesAgree(string $existingTitle, string $incomingTitle): void
    {
        $existing = $this->bookWithIsbn($existingTitle);

        $found = $this->service->findExistingBook('/incoming/source', ['isbn' => self::ISBN, 'title' => $incomingTitle]);

        $this->assertSame($existing->id, $found?->id);
    }

    #[Test]
    public function ignoresIsbnMatchWhenTitleDiffers(): void
    {
        $this->bookWithIsbn('Hurricane Gold');

        $found = $this->service->findExistingBook('/incoming/source', ['isbn' => self::ISBN, 'title' => 'By Royal Command']);

        $this->assertNull($found);
    }

    #[Test]
    public function picksTheBookWhoseTitleAgreesAmongSharedIsbns(): void
    {
        $this->bookWithIsbn('Hurricane Gold');
        $match = $this->bookWithIsbn('By Royal Command');

        $found = $this->service->findExistingBook('/incoming/source', ['isbn' => self::ISBN, 'title' => 'By Royal Command']);

        $this->assertSame($match->id, $found?->id);
    }

    private function bookWithIsbn(string $title): Book
    {
        return Book::factory()->create([
            'title' => $title,
            'isbn' => self::ISBN,
            'directory_path' => 'Action/Somebody/' . $title,
        ]);
    }
}
