<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\HardcoverService;
use App\Traits\HandlesLibraryJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnrichVsiBooks extends Command
{
    use HandlesLibraryJson;

    protected $signature = 'books:enrich-vsi
                            {--dry-run : Preview changes without applying them}
                            {--genre-only : Only fix genre, skip Hardcover enrichment}
                            {--split-bolinda : Also move Bolinda Beginner Guides to their own collection}
                            {--id= : Only process a specific book ID}
                            {--limit= : Maximum number of books to process}';

    protected $description = 'Enrich imported VSI/Bolinda books: assign Non Fiction genre, fetch author/cover/description from Hardcover, optionally split Bolinda collection';

    private const VSI_GENRE_NAME = 'Non Fiction';

    private const VSI_SERIES_NAME = 'Very Short Introductions';

    private const BOLINDA_SERIES_NAME = 'Bolinda Beginner Guides';

    private const VSI_SUFFIX = 'A Very Short Introduction';

    private const BOLINDA_SUFFIX = 'Bolinda Beginner Guides';

    private const VSI_BASE_PATH = 'Non Fiction/VA/Very Short Introductions';

    private const BOLINDA_BASE_PATH = 'Non Fiction/VA/Bolinda Beginner Guides';

    private BookImportService $importService;

    private HardcoverService $hardcoverService;

    private bool $dryRun = false;

    private int $genreFixed = 0;

    private int $authorFixed = 0;

    private int $coverFixed = 0;

    private int $descFixed = 0;

    private int $bolindaMoved = 0;

    private int $skipped = 0;

    private int $errors = 0;

    public function handle(BookImportService $importService, HardcoverService $hardcoverService): int
    {
        $this->importService  = $importService;
        $this->hardcoverService = $hardcoverService;
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn('DRY-RUN mode: no changes will be written');
        }

        if (!$this->hardcoverService->isAvailable() && !$this->option('genre-only')) {
            $this->error('Hardcover API is not configured. Set HARDCOVER_API_KEY / hardcover.api_token in .env.');
            $this->info('You can run with --genre-only to only fix genres without the API.');
            return Command::FAILURE;
        }

        $query = Book::query()
            ->where(function ($q) {
                $q->where('directory_path', 'like', self::VSI_BASE_PATH . '/%')
                  ->orWhere('directory_path', 'like', self::BOLINDA_BASE_PATH . '/%');
            })
            ->orderBy('id');

        if ($this->option('id')) {
            $query->where('id', (int) $this->option('id'));
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $total = $query->count();
        $this->info("Processing {$total} VSI/Bolinda books...");

        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        $nonFictionGenre = Genre::firstOrCreate(['name' => self::VSI_GENRE_NAME]);
        $vsiSeries = Series::firstOrCreate(['name' => self::VSI_SERIES_NAME], ['is_collection' => true]);
        $bolindaSeries = null;

        $query->chunk(50, function ($books) use ($bookRoot, $nonFictionGenre, $vsiSeries, &$bolindaSeries) {
            foreach ($books as $book) {
                $this->processBook($book, $bookRoot, $nonFictionGenre, $vsiSeries, $bolindaSeries);
            }
        });

        $this->newLine();
        $this->info('══ Summary ═══════════════════════════════════════════');
        $this->info("  Genre fixed:   {$this->genreFixed}");
        $this->info("  Authors added: {$this->authorFixed}");
        $this->info("  Covers added:  {$this->coverFixed}");
        $this->info("  Descs added:   {$this->descFixed}");
        $this->info("  Bolinda moved: {$this->bolindaMoved}");
        $this->info("  Skipped:       {$this->skipped}");
        $this->info("  Errors:        {$this->errors}");

        if ($this->dryRun) {
            $this->warn('DRY-RUN: no changes were written');
        }

        return Command::SUCCESS;
    }

    private function processBook(Book $book, string $bookRoot, Genre $nonFictionGenre, Series $vsiSeries, ?Series &$bolindaSeries): void
    {
        $isBolinda = $this->isBolindaBook($book);
        $seriesLabel = $isBolinda ? self::BOLINDA_SERIES_NAME : self::VSI_SERIES_NAME;

        $this->line("  [{$book->id}] \"{$book->title}\"" . ($isBolinda ? ' [Bolinda]' : ''));

        // ── 1. Fix genre ──────────────────────────────────────────────────────
        $hasGenre = $book->genres()->count() > 0;
        if (!$hasGenre) {
            if (!$this->dryRun) {
                $book->genres()->syncWithPivotValues([$nonFictionGenre->id], ['is_primary' => true]);
            }
            $this->line("      genre → " . self::VSI_GENRE_NAME);
            $this->genreFixed++;
        }

        // ── 2. Move Bolinda books to their own collection ─────────────────────
        if ($isBolinda && $this->option('split-bolinda')) {
            if ($bolindaSeries === null) {
                $bolindaSeries = $this->getOrCreateBolindaSeries();
            }
            $this->moveToBolindaCollection($book, $bookRoot, $bolindaSeries);
        }

        // ── 3. Enrich from Hardcover ──────────────────────────────────────────
        if (!$this->option('genre-only')) {
            $this->enrichFromHardcover($book, $bookRoot, $isBolinda);
        }

        // ── 4. Update librarian.json ───────────────────────────────────────────
        if (!$this->dryRun) {
            try {
                $book->load(['authors', 'narrators', 'genres', 'series', 'publisher']);
                $this->updateLibraryJson($book);
            } catch (\Throwable $e) {
                $this->warn("      librarian.json update failed: " . $e->getMessage());
            }
        }
    }

    private function enrichFromHardcover(Book $book, string $bookRoot, bool $isBolinda): void
    {
        $hasAuthor = $book->authors()->count() > 0;
        $hasCover  = !empty($book->cover_image);
        $hasDesc   = !empty($book->description);

        if ($hasAuthor && $hasCover && $hasDesc) {
            $this->skipped++;
            return;
        }

        // Build the full search title including the series suffix for best match
        $searchTitle = $this->buildSearchTitle($book->title, $isBolinda);
        $this->line("      hardcover search: \"{$searchTitle}\"");

        try {
            $results = $this->hardcoverService->searchBooks($searchTitle, ['limit' => 5]);
        } catch (\Throwable $e) {
            $this->warn("      Hardcover API error: " . $e->getMessage());
            Log::warning('EnrichVsiBooks: Hardcover search failed', ['book_id' => $book->id, 'error' => $e->getMessage()]);
            $this->errors++;
            return;
        }

        if (empty($results)) {
            $this->warn("      no Hardcover results for \"{$searchTitle}\"");
            $this->skipped++;
            return;
        }

        $match = $this->pickBestMatch($results, $book->title);
        if ($match === null) {
            $this->warn("      no suitable match found");
            $this->skipped++;
            return;
        }

        $this->line("      matched: \"{$match['title']}\" (id={$match['id']})");

        // Apply author
        if (!$hasAuthor && !empty($match['author'])) {
            $rawAuthors = is_array($match['author']) ? $match['author'] : [$match['author']];
            // Normalise whitespace and deduplicate
            $authors = array_values(array_unique(array_filter(
                array_map(fn ($n) => preg_replace('/\s+/', ' ', trim((string) $n)), $rawAuthors),
                fn ($n) => $n !== ''
            )));
            if (!$this->dryRun && !empty($authors)) {
                $book->authors()->detach();
                $authorIds = [];
                foreach ($authors as $name) {
                    $author = Author::firstOrCreate(['name' => $name]);
                    $authorIds[] = $author->id;
                }
                $book->authors()->syncWithoutDetaching(array_unique($authorIds));
            }
            $this->line("      author → " . implode(', ', $authors));
            $this->authorFixed++;
        }

        // Apply description
        if (!$hasDesc && !empty($match['description'])) {
            if (!$this->dryRun) {
                $book->description = $match['description'];
                $book->save();
            }
            $this->line("      description → " . mb_substr($match['description'], 0, 60) . '...');
            $this->descFixed++;
        }

        // Apply year/release_date
        if (!empty($match['publishedYear']) && !$book->release_date) {
            if (!$this->dryRun) {
                $book->release_date = $match['publishedYear'] . '-01-01';
                $book->save();
            }
        }

        // Apply cover
        if (!$hasCover && !empty($match['coverImageUrl'])) {
            $dirPath = $bookRoot . '/' . $book->directory_path;
            if (!$this->dryRun) {
                $coverPath = $this->importService->downloadCoverImage($match['coverImageUrl'], $dirPath, 'hardcover');
                if ($coverPath) {
                    $book->cover_image = ltrim(str_replace($bookRoot, '', $coverPath), '/');
                    $book->save();
                    $this->line("      cover → {$book->cover_image}");
                    $this->coverFixed++;
                } else {
                    $this->warn("      cover download failed");
                }
            } else {
                $this->line("      cover → (would download from {$match['coverImageUrl']})");
                $this->coverFixed++;
            }
        }
    }

    /**
     * Build the full title string to search on Hardcover, including the series suffix.
     * VSI: "Ancient Egypt" → "Ancient Egypt A Very Short Introduction"
     * Bolinda: "Aesthetics" → "Aesthetics Bolinda Beginner Guides"
     */
    private function buildSearchTitle(string $title, bool $isBolinda): string
    {
        if ($isBolinda) {
            return $title . ' ' . self::BOLINDA_SUFFIX;
        }

        return $title . ' ' . self::VSI_SUFFIX;
    }

    /**
     * Pick the best match from Hardcover results.
     * Prefers results where our short title appears in the result title.
     */
    private function pickBestMatch(array $results, string $bookTitle): ?array
    {
        $bookTitleLower = mb_strtolower($bookTitle);
        $bestScore = -1;
        $best = null;

        foreach ($results as $result) {
            $resultTitle = mb_strtolower($result['title'] ?? '');
            $score = 0;

            // Title starts with or exactly matches our book title
            if (str_starts_with($resultTitle, $bookTitleLower)) {
                $score += 3;
            } elseif (str_contains($resultTitle, $bookTitleLower)) {
                $score += 2;
            }

            // Result title contains VSI/Bolinda markers
            if (str_contains($resultTitle, 'very short introduction') || str_contains($resultTitle, 'beginner guide')) {
                $score += 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $result;
            }
        }

        // Require meaningful relevance — score of 1 means only title substring match,
        // which risks wrong results (e.g. "The Brain" matching "The Brain Storm").
        // Require score >= 2 (either title-starts-with match, or substring + VSI marker).
        if ($bestScore < 2) {
            return null;
        }

        return $best;
    }

    /**
     * Determine if a book came from the Bolinda Beginner Guides download.
     * The original source filename is stored as the first key in file_tags JSON.
     */
    private function isBolindaBook(Book $book): bool
    {
        // Check current directory path first
        if (str_contains($book->directory_path, 'Bolinda')) {
            return true;
        }

        // Check original filename in file_tags (already decoded array via Eloquent cast)
        $tags = $book->file_tags;
        if (!is_array($tags)) {
            return false;
        }

        $firstKey = array_key_first($tags);
        if ($firstKey === null) {
            return false;
        }

        return stripos($firstKey, 'Bolinda') !== false;
    }

    private function getOrCreateBolindaSeries(): Series
    {
        return Series::firstOrCreate(
            ['name' => self::BOLINDA_SERIES_NAME],
            ['is_collection' => true]
        );
    }

    /**
     * Move a Bolinda book from the VSI collection directory to the Bolinda collection directory.
     */
    private function moveToBolindaCollection(Book $book, string $bookRoot, Series $bolindaSeries): void
    {
        if (!str_starts_with($book->directory_path, self::VSI_BASE_PATH . '/')) {
            return;
        }

        $subDir   = substr($book->directory_path, strlen(self::VSI_BASE_PATH) + 1);
        $newPath  = self::BOLINDA_BASE_PATH . '/' . $subDir;
        $oldAbsPath = $bookRoot . '/' . $book->directory_path;
        $newAbsPath = $bookRoot . '/' . $newPath;

        $this->line("      bolinda move → {$newPath}");

        if ($this->dryRun) {
            $this->bolindaMoved++;
            return;
        }

        // Move directory
        if (is_dir($oldAbsPath) && !is_dir($newAbsPath)) {
            $parentDir = dirname($newAbsPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
            rename($oldAbsPath, $newAbsPath);
        }

        DB::transaction(function () use ($book, $newPath, $bolindaSeries) {
            // Update path
            $book->directory_path = $newPath;
            $book->save();

            // Swap series: remove VSI, add Bolinda
            $vsiSeries = Series::where('name', self::VSI_SERIES_NAME)->first();
            if ($vsiSeries) {
                $book->series()->detach($vsiSeries->id);
            }
            if (!$book->series()->where('series_id', $bolindaSeries->id)->exists()) {
                $book->series()->attach($bolindaSeries->id, ['series_number' => null]);
            }
        });

        $this->bolindaMoved++;
    }
}
