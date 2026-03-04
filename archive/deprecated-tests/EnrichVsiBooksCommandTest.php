<?php

declare(strict_types=1);

namespace Tests\Deprecated;

use App\Models\Book;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\HardcoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

#[\PHPUnit\Framework\Attributes\Group('deprecated')]
class EnrichVsiBooksCommandTest extends TestCase
{
    use RefreshDatabase;

    private const VSI_PATH = 'Non Fiction/VA/Very Short Introductions';

    private const BOLINDA_PATH = 'Non Fiction/VA/Bolinda Beginner Guides';

    #[\PHPUnit\Framework\Attributes\Test]
    public function testGenreOnlyFlagAssignsNonFictionWithoutCallingHardcover(): void
    {
        $book = Book::factory()->create([
            'title' => 'Ancient Egypt',
            'directory_path' => self::VSI_PATH . '/Ancient Egypt',
        ]);

        $hardcoverMock = $this->createMock(HardcoverService::class);
        $hardcoverMock->expects($this->never())->method('searchBooks');
        $hardcoverMock->method('isAvailable')->willReturn(true);
        $this->app->instance(HardcoverService::class, $hardcoverMock);

        $this->artisan('books:enrich-vsi', ['--genre-only' => true])
            ->assertExitCode(0);

        $book->refresh();
        $this->assertTrue($book->genres()->where('name', 'Non Fiction')->exists());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testEnrichFromHardcoverSetsAuthorAndDescription(): void
    {
        $book = Book::factory()->create([
            'title' => 'Chaos',
            'directory_path' => self::VSI_PATH . '/Chaos',
            'description' => null,
            'cover_image' => null,
        ]);

        $hardcoverMock = $this->createMock(HardcoverService::class);
        $hardcoverMock->method('isAvailable')->willReturn(true);
        $hardcoverMock->method('searchBooks')
            ->with('Chaos A Very Short Introduction', $this->anything())
            ->willReturn([
                [
                    'id' => 999,
                    'title' => 'Chaos: A Very Short Introduction',
                    'author' => ['Leonard Smith'],
                    'description' => 'An introduction to chaos theory.',
                    'publishedYear' => '2007',
                    'coverImageUrl' => null,
                ],
            ]);

        $this->app->instance(HardcoverService::class, $hardcoverMock);

        $importServiceMock = $this->createMock(BookImportService::class);
        $importServiceMock->method('downloadCoverImage')->willReturn(null);
        $this->app->instance(BookImportService::class, $importServiceMock);

        $this->artisan('books:enrich-vsi')
            ->assertExitCode(0);

        $book->refresh();
        $this->assertTrue($book->authors()->where('name', 'Leonard Smith')->exists());
        $this->assertSame('An introduction to chaos theory.', $book->description);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testDryRunDoesNotPersistChanges(): void
    {
        $book = Book::factory()->create([
            'title' => 'Chaos',
            'directory_path' => self::VSI_PATH . '/Chaos',
            'description' => null,
        ]);

        $hardcoverMock = $this->createMock(HardcoverService::class);
        $hardcoverMock->method('isAvailable')->willReturn(true);
        $hardcoverMock->method('searchBooks')->willReturn([
            [
                'id' => 999,
                'title' => 'Chaos: A Very Short Introduction',
                'author' => ['Leonard Smith'],
                'description' => 'An introduction to chaos theory.',
                'publishedYear' => '2007',
                'coverImageUrl' => null,
            ],
        ]);
        $this->app->instance(HardcoverService::class, $hardcoverMock);

        $this->artisan('books:enrich-vsi', ['--dry-run' => true])
            ->assertExitCode(0);

        $book->refresh();
        $this->assertEmpty($book->description);
        $this->assertEquals(0, $book->authors()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testBadMatchBelowScoreThresholdIsRejected(): void
    {
        $book = Book::factory()->create([
            'title' => 'The Brain',
            'directory_path' => self::VSI_PATH . '/The Brain (Bolinda)',
            'file_tags' => ['TheBrainBolindaBeginnerGuides_mp332.mp3' => []],
            'description' => null,
        ]);

        $hardcoverMock = $this->createMock(HardcoverService::class);
        $hardcoverMock->method('isAvailable')->willReturn(true);
        $hardcoverMock->method('searchBooks')->willReturn([
            [
                'id' => 1234,
                'title' => 'The Brain Storm',
                'author' => ['Wrong Author'],
                'description' => 'Wrong book.',
                'publishedYear' => '2020',
                'coverImageUrl' => null,
            ],
        ]);
        $this->app->instance(HardcoverService::class, $hardcoverMock);

        $this->artisan('books:enrich-vsi', ['--genre-only' => true])
            ->assertExitCode(0);

        $book->refresh();
        $this->assertEquals(0, $book->authors()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testBolindaBooksAreIdentifiedByFileTags(): void
    {
        $bolindaBook = Book::factory()->create([
            'title' => 'Aesthetics',
            'directory_path' => self::VSI_PATH . '/Aesthetics',
            'file_tags' => ['AestheticsBolindaBeginnerGuides_mp332.mp3' => []],
        ]);

        $regularBook = Book::factory()->create([
            'title' => 'Chaos',
            'directory_path' => self::VSI_PATH . '/Chaos',
            'file_tags' => ['ChaosBolindaBeginnerGuides_mp332.mp3' => []],
        ]);

        $hardcoverMock = $this->createMock(HardcoverService::class);
        $hardcoverMock->method('isAvailable')->willReturn(true);
        $hardcoverMock->method('searchBooks')->willReturn([]);
        $this->app->instance(HardcoverService::class, $hardcoverMock);

        $this->artisan('books:enrich-vsi', ['--split-bolinda' => true, '--genre-only' => true])
            ->assertExitCode(0);

        $bolindaBook->refresh();
        $this->assertStringStartsWith(
            self::BOLINDA_PATH,
            $bolindaBook->directory_path,
            'Bolinda book should have been moved to Bolinda collection path'
        );

        $bolindaSeriesExists = Series::where('name', 'Bolinda Beginner Guides')->exists();
        $this->assertTrue($bolindaSeriesExists);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testHardcoverApiUnavailableBlocksRunWithoutGenreOnly(): void
    {
        $hardcoverMock = $this->createMock(HardcoverService::class);
        $hardcoverMock->method('isAvailable')->willReturn(false);
        $this->app->instance(HardcoverService::class, $hardcoverMock);

        $this->artisan('books:enrich-vsi')
            ->assertExitCode(1);
    }
}
