<?php

declare(strict_types=1);

namespace App\Console\Commands\Deprecated;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\BookMetadataScraperService;
use App\Services\HardcoverService;
use App\Traits\HandlesLibraryJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnrichVsiBooks extends Command
{
    use HandlesLibraryJson;

    protected $signature = 'deprecated:enrich-vsi
                            {--dry-run : Preview changes without applying them}
                            {--genre-only : Only fix genre, skip Hardcover enrichment}
                            {--split-bolinda : Also move Bolinda Beginner Guides to their own collection}
                            {--fix-covers : Re-download covers that are recorded in DB but missing from disk}
                            {--goodreads : Also try Goodreads for books still missing author after Hardcover}
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

    private int $coversFixed = 0;

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

        // ── Fix-covers pass: re-download covers recorded in DB but missing on disk ──
        if ($this->option('fix-covers')) {
            $this->newLine();
            $this->info('Re-downloading missing cover files...');
            $this->fixMissingCovers($bookRoot);
        }

        $this->newLine();
        $this->info('══ Summary ═══════════════════════════════════════════');
        $this->info("  Genre fixed:      {$this->genreFixed}");
        $this->info("  Authors added:    {$this->authorFixed}");
        $this->info("  Covers added:     {$this->coverFixed}");
        $this->info("  Covers re-dl:     {$this->coversFixed}");
        $this->info("  Descs added:      {$this->descFixed}");
        $this->info("  Bolinda moved:    {$this->bolindaMoved}");
        $this->info("  Skipped:          {$this->skipped}");
        $this->info("  Errors:           {$this->errors}");

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
            // Fallback: try bare title without the VSI/Bolinda suffix
            try {
                $results = $this->hardcoverService->searchBooks($book->title, ['limit' => 5]);
            } catch (\Throwable $e) {
                $this->warn("      Hardcover API error (fallback): " . $e->getMessage());
                $this->errors++;
                return;
            }

            if (empty($results)) {
                $this->warn("      no Hardcover results for \"{$book->title}\"");
                $this->skipped++;
                return;
            }

            $this->line("      fallback search: \"{$book->title}\"");
        }

        $match = $this->pickBestMatch($results, $book->title);
        if ($match === null) {
            // Fallback to Goodreads if flag set
            if ($this->option('goodreads')) {
                $this->enrichFromGoodreads($book, $isBolinda);
            } else {
                $this->warn("      no suitable match found");
                $this->skipped++;
            }
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
            if (!$this->dryRun) {
                // downloadCoverImage expects the relative directory_path (not absolute).
                // It saves the file under book_root/directory_path/ and returns just the filename.
                // cover_image in DB stores just the filename (new convention).
                $coverFilename = $this->importService->downloadCoverImage($match['coverImageUrl'], $book->directory_path, 'hardcover');
                if ($coverFilename) {
                    $book->cover_image = $coverFilename;
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
    private function pickBestMatch(array $results, string $bookTitle, int $minScore = 2): ?array
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

        if ($bestScore < $minScore) {
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

    /**
     * Re-download covers that are stored in DB but whose file is missing on disk.
     * Searches Hardcover again to get the URL, then downloads to the correct location.
     */
    private function fixMissingCovers(string $bookRoot): void
    {
        /** @var iterable<Book> $books */
        $books = Book::query()
            ->where(function ($q) {
                $q->where('directory_path', 'like', self::VSI_BASE_PATH . '/%')
                  ->orWhere('directory_path', 'like', self::BOLINDA_BASE_PATH . '/%');
            })
            ->get();

        $checked = 0;
        foreach ($books as $book) {
            // Skip if cover_image is set and the file exists on disk
            if (!empty($book->cover_image)) {
                $absPath = $bookRoot . '/' . $book->directory_path . '/' . $book->cover_image;
                if (file_exists($absPath)) {
                    continue;
                }
            }

            $checked++;
            $isBolinda = $this->isBolindaBook($book);
            $searchTitle = $this->buildSearchTitle($book->title, $isBolinda);

            $this->line("  [{$book->id}] \"{$book->title}\" — missing cover, searching...");

            try {
                $results = $this->hardcoverService->searchBooks($searchTitle, ['limit' => 5]) ?? [];
                if (empty($results)) {
                    $results = $this->hardcoverService->searchBooks($book->title, ['limit' => 5]) ?? [];
                }
            } catch (\Throwable $e) {
                $this->warn("    Hardcover error: " . $e->getMessage());
                $this->errors++;
                continue;
            }

            // Use minScore=0 for cover searches — any title match is acceptable for a cover image
            $match = !empty($results) ? $this->pickBestMatch($results, $book->title, 0) : null;

            // Also try Goodreads then Amazon if enabled and no Hardcover cover URL
            if (($match === null || empty($match['coverImageUrl'])) && $this->option('goodreads')) {
                $external = $this->fetchFromGoodreads($book->title, $isBolinda);
                $source = 'goodreads';
                if ($external === null || empty($external['coverImageUrl'])) {
                    $external = $this->fetchFromAmazon($book->title, $isBolinda);
                    $source = 'amazon';
                }
                if ($external !== null && !empty($external['coverImageUrl'])) {
                    if (!$this->dryRun) {
                        $filename = $this->importService->downloadCoverImage($external['coverImageUrl'], $book->directory_path, $source);
                        if ($filename) {
                            $book->cover_image = $filename;
                            $book->save();
                            $this->line("    cover re-downloaded ({$source}) → {$filename}");
                            $this->coversFixed++;
                        } else {
                            $this->warn("    download failed");
                        }
                    } else {
                        $this->line("    would re-download ({$source}) from {$external['coverImageUrl']}");
                        $this->coversFixed++;
                    }
                } else {
                    $this->warn("    no cover URL found from any source");
                }
                continue;
            }

            if ($match === null || empty($match['coverImageUrl'])) {
                $this->warn("    no cover URL found");
                continue;
            }

            if (!$this->dryRun) {
                $filename = $this->importService->downloadCoverImage($match['coverImageUrl'], $book->directory_path, 'hardcover');
                if ($filename) {
                    $book->cover_image = $filename;
                    $book->save();
                    $this->line("    cover re-downloaded → {$filename}");
                    $this->coversFixed++;
                } else {
                    $this->warn("    download failed");
                }
            } else {
                $this->line("    would re-download from {$match['coverImageUrl']}");
                $this->coversFixed++;
            }
        }

        $this->info("  Checked {$checked} books with missing cover files.");
    }

    /**
     * Apply Goodreads data directly to a Book model (author, description, cover).
     */
    private function enrichFromGoodreads(Book $book, bool $isBolinda): void
    {
        $this->line('      goodreads search: "' . $book->title . '"');
        $data = $this->fetchFromGoodreads($book->title, $isBolinda);

        if ($data === null) {
            $this->line('      trying Amazon...');
            $data = $this->fetchFromAmazon($book->title, $isBolinda);
        }

        if ($data === null) {
            $this->warn('      no results from Goodreads or Amazon');
            $this->skipped++;
            return;
        }

        if (!empty($data['author']) && $book->authors()->count() === 0) {
            if (!$this->dryRun) {
                $book->authors()->detach();
                $authorIds = [];
                foreach ($data['author'] as $name) {
                    $name = preg_replace('/\s+/', ' ', trim((string) $name));
                    if ($name === '') {
                        continue;
                    }

                    $author = Author::firstOrCreate(['name' => $name]);
                    $authorIds[] = $author->id;
                }
                if (!empty($authorIds)) {
                    $book->authors()->syncWithoutDetaching(array_unique($authorIds));
                }
            }
            $this->line('      author (GR) → ' . implode(', ', $data['author']));
            $this->authorFixed++;
        }

        if (!empty($data['description']) && empty($book->description)) {
            if (!$this->dryRun) {
                $book->description = $data['description'];
                $book->save();
            }
            $this->line('      desc (GR) → ' . mb_substr($data['description'], 0, 60) . '...');
            $this->descFixed++;
        }

        if (!empty($data['coverImageUrl']) && empty($book->cover_image)) {
            if (!$this->dryRun) {
                $filename = $this->importService->downloadCoverImage($data['coverImageUrl'], $book->directory_path, 'goodreads');
                if ($filename) {
                    $book->cover_image = $filename;
                    $book->save();
                    $this->line('      cover (GR) → ' . $filename);
                    $this->coverFixed++;
                }
            } else {
                $this->line('      cover (GR) → (would download)');
                $this->coverFixed++;
            }
        }
    }

    private function fetchFromGoodreads(string $title, bool $isBolinda): ?array
    {
        return app(BookMetadataScraperService::class)
            ->fetchFromGoodreads($this->buildSearchTitle($title, $isBolinda));
    }

    private function fetchFromAmazon(string $title, bool $isBolinda): ?array
    {
        return app(BookMetadataScraperService::class)
            ->fetchFromAmazon($this->buildSearchTitle($title, $isBolinda));
    }
}
