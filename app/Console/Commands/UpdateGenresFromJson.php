<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Genre;
use App\Services\GenreMappingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateGenresFromJson extends Command
{
    protected $signature = 'books:update-genres-from-json
                            {--dry-run : Show what would be changed without making changes}
                            {--only-invalid : Only update books with invalid genres}
                            {--force : Update even if book already has a valid primary genre}';

    protected $description = 'Update book genres by reading actual OpenAudible books.json files';

    protected GenreMappingService $genreMappingService;

    public function __construct(GenreMappingService $genreMappingService)
    {
        parent::__construct();
        $this->genreMappingService = $genreMappingService;
    }

    public function handle(): int
    {
        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

        $this->info('🔍 Loading OpenAudible books.json files...');

        // Load OpenAudible JSON files
        $openAudibleData = $this->loadOpenAudibleData();

        if (empty($openAudibleData)) {
            $this->error('No OpenAudible books.json files found');
            return 1;
        }

        $this->info('Loaded ' . count($openAudibleData) . ' books from OpenAudible JSON');
        $this->newLine();

        $query = Book::query();

        if ($this->option('only-invalid')) {
            $validGenres = [
                'Science Fiction', 'Fantasy', 'LitRPG', 'Romance', 'History',
                'Historical Fiction', 'Non Fiction', 'Religion', 'Church',
                'Kids', 'Action', 'Classic', 'General Fiction', 'Computer',
                'Western', 'Horror', 'Mystery', 'Other', 'Science',
            ];

            $invalidGenreIds = Genre::whereNotIn('name', $validGenres)->pluck('id');
            $query->whereHas('genres', function ($q) use ($invalidGenreIds) {
                $q->whereIn('genre_id', $invalidGenreIds);
            });

            $this->info('Filtering to only books with invalid genres...');
        }

        $books = $query->get();
        $this->info("Found {$books->count()} books to check");
        $this->newLine();

        $updated = 0;
        $skipped = 0;
        $noJson = 0;
        $noGenre = 0;

        foreach ($books as $book) {
            // Match OpenAudible book by title and author
            $openAudibleBook = $this->findOpenAudibleBookByTitleAuthor($book, $openAudibleData);

            if (!$openAudibleBook || empty($openAudibleBook['genre'])) {
                $noJson++;
                continue;
            }

            try {
                $jsonGenre = $openAudibleBook['genre'];

                // Map to valid primary genre
                $validGenre = $this->genreMappingService->mapToPrimaryGenre($jsonGenre);

                // Get current primary genre
                /** @var \App\Models\Genre|null $currentPrimaryGenre */
                $currentPrimaryGenre = $book->genres()->wherePivot('is_primary', true)->first();
                $currentGenreName = $currentPrimaryGenre ? $currentPrimaryGenre->name : 'NONE';

                // Skip if already correct, unless --force is used
                if ($currentGenreName === $validGenre && !$this->option('force')) {
                    $skipped++;
                    continue;
                }

                $this->info("📖 {$book->title}");
                $this->line("   Current: {$currentGenreName}");
                $this->line("   JSON: {$jsonGenre}");
                $this->line("   Mapped: {$validGenre}");

                if (!$this->option('dry-run')) {
                    DB::beginTransaction();
                    try {
                        // Get or create the valid genre
                        $validGenreModel = Genre::firstOrCreate(['name' => $validGenre]);

                        // Remove old primary genre
                        if ($currentPrimaryGenre) {
                            $book->genres()->detach($currentPrimaryGenre->id);
                        }

                        // Add new genre as primary
                        $book->genres()->syncWithoutDetaching([
                            $validGenreModel->id => ['is_primary' => true],
                        ]);

                        DB::commit();
                        $updated++;
                        $this->line("   ✓ Updated");
                    } catch (\Exception $e) {
                        DB::rollBack();
                        $this->error("   ✗ Error: " . $e->getMessage());
                    }
                } else {
                    $this->line("   [DRY RUN] Would update");
                    $updated++;
                }

                $this->newLine();
            } catch (\Exception $e) {
                $this->error("Error processing {$book->title}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("📊 Summary:");
        $this->line("  Books checked: {$books->count()}");
        $this->line("  Updated: {$updated}");
        $this->line("  Skipped (already correct): {$skipped}");
        $this->line("  No JSON file: {$noJson}");
        $this->line("  No genre in JSON: {$noGenre}");

        if ($this->option('dry-run')) {
            $this->warn("🔍 [DRY RUN] No changes were made");
        }

        return 0;
    }

    /**
     * Load OpenAudible books.json files
     */
    protected function loadOpenAudibleData(): array
    {
        $openAudiblePaths = [
            '/media/lyra_data1/audiobooks/OpenAudible/books.json',
            '/media/lyra_data1/audiobooks/OpenAudible/web/books.json',
            '/media/lyra_data1/audiobooks/OpenAudibleKyler/books.json',
        ];

        $allBooks = [];

        foreach ($openAudiblePaths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            try {
                $json = json_decode(file_get_contents($path), true);
                if (!is_array($json)) {
                    continue;
                }

                foreach ($json as $book) {
                    if (!empty($book['genre'])) {
                        $allBooks[] = $book;
                    }
                }
            } catch (\Exception $e) {
                $this->warn("Error loading {$path}: " . $e->getMessage());
            }
        }

        return $allBooks;
    }

    /**
     * Find OpenAudible book by matching title and author
     */
    protected function findOpenAudibleBookByTitleAuthor($book, array $openAudibleData): ?array
    {
        $bookTitle = strtolower(trim($book->title));
        $bookAuthors = $book->authors->pluck('name')->map(fn ($name) => strtolower(trim($name)))->toArray();

        $bestMatch = null;
        $bestScore = 0;

        foreach ($openAudibleData as $oaBook) {
            $oaTitle = strtolower(trim($oaBook['title'] ?? ''));

            // Match title - exact match or one contains the other
            $titleMatches = $oaTitle === $bookTitle
                || str_contains($oaTitle, $bookTitle)
                || str_contains($bookTitle, $oaTitle);

            if (!$titleMatches) {
                continue;
            }

            // Calculate match score
            $score = 0;

            // Exact title match is best
            if ($oaTitle === $bookTitle) {
                $score += 100;
            } elseif (str_contains($oaTitle, $bookTitle)) {
                $score += 50;
            } else {
                $score += 25;
            }

            // Check author match (bonus points, but not required)
            $oaAuthor = $oaBook['author'] ?? '';
            if (is_string($oaAuthor)) {
                $oaAuthor = strtolower(trim($oaAuthor));
                foreach ($bookAuthors as $author) {
                    if ($author === $oaAuthor) {
                        $score += 50;
                    } elseif (str_contains($oaAuthor, $author) || str_contains($author, $oaAuthor)) {
                        $score += 25;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $oaBook;
            }
        }

        return $bestMatch;
    }
}
