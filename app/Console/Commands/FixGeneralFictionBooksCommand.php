<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Genre;
use App\Services\OpenAudibleParser;
use App\Traits\GenreMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class FixGeneralFictionBooksCommand extends Command
{
    use GenreMapping;

    protected $signature = 'books:fix-general-fiction-oct20
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Fix misplaced books in General Fiction from 10/20/2025 import';

    protected ?OpenAudibleParser $parser = null;
    protected ?array $openAudibleBooks = null;

    public function handle()
    {
        $this->info('Fixing misplaced General Fiction books from 10/20/2025...');

        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Load OpenAudible books.json
        $this->loadOpenAudibleData();

        // Books to check (titles from the scan)
        $bookTitles = [
            'Achilles',
            'A Knot in the Grain - And Other Stories',
            'Age of Swords',
            'Age of Legend',
            'Ancient Debts',
            'Alethea',
            'America',
            'Anne of Green Gables',
        ];

        $fixed = 0;
        $errors = 0;

        foreach ($bookTitles as $title) {
            try {
                if ($this->fixBook($title, $dryRun)) {
                    $fixed++;
                }
            } catch (\Exception $e) {
                $this->error("Error fixing {$title}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Fixed: {$fixed}");
        $this->info("  Errors: {$errors}");

        return Command::SUCCESS;
    }

    protected function loadOpenAudibleData(): void
    {
        $booksJsonPath = '/media/lyra_data1/audiobooks/OpenAudible/books.json';

        if (!File::exists($booksJsonPath)) {
            $this->error("books.json not found at {$booksJsonPath}");
            exit(1);
        }

        $this->parser = app(OpenAudibleParser::class);
        $this->openAudibleBooks = $this->parser->loadBooksJson('/media/lyra_data1/audiobooks/OpenAudible');
        $this->info("Loaded " . count($this->openAudibleBooks) . " books from OpenAudible");
    }

    protected function fixBook(string $title, bool $dryRun): bool
    {
        // Find book in database
        $book = Book::with(['authors', 'series', 'genres'])
            ->where('title', 'LIKE', "%{$title}%")
            ->where('updated_at', '>=', '2025-10-20')
            ->where('updated_at', '<', '2025-10-21')
            ->first();

        if (!$book) {
            $this->warn("Book not found in database: {$title}");
            return false;
        }

        // Find in OpenAudible
        $oaMetadata = $this->findInOpenAudible($book->title);
        if (!$oaMetadata) {
            $this->warn("Book not found in OpenAudible: {$book->title}");
            return false;
        }

        $oaGenre = $oaMetadata['genre'] ?? null;
        if (!$oaGenre) {
            $this->warn("No genre in OpenAudible for: {$book->title}");
            return false;
        }

        // Parse genre
        $genreParts = explode(':', $oaGenre);
        $rootCategory = trim($genreParts[0]);
        $specificGenre = trim(end($genreParts));

        // Map genres
        $mappedPrimary = $this->mapToValidGenre($specificGenre);
        $mappedSecondary = null;

        // Check if we should add secondary genre
        $skipSecondary = (
            $rootCategory === $specificGenre ||
            ($rootCategory === 'Science Fiction & Fantasy' &&
             ($specificGenre === 'Science Fiction' || $specificGenre === 'Fantasy'))
        );

        if (!$skipSecondary && count($genreParts) > 1) {
            $mappedSecondary = $this->mapToValidGenre($rootCategory);
        }

        $this->info("Book: {$book->title}");
        $this->line("  Current Genre: General Fiction");
        $this->line("  OpenAudible: {$oaGenre}");
        $this->line("  New Primary: {$mappedPrimary}");
        if ($mappedSecondary) {
            $this->line("  New Secondary: {$mappedSecondary}");
        }

        if ($dryRun) {
            $this->line("  [DRY RUN] Would update genres and move files");
            return true;
        }

        // Update genres
        $genreIds = [];
        $primaryGenre = Genre::firstOrCreate(['name' => $mappedPrimary]);
        $genreIds[] = $primaryGenre->id;

        if ($mappedSecondary && $mappedSecondary !== $mappedPrimary) {
            $secondaryGenre = Genre::firstOrCreate(['name' => $mappedSecondary]);
            $genreIds[] = $secondaryGenre->id;
        }

        $book->genres()->sync($genreIds);

        // Calculate new directory path
        $oldPath = $book->directory_path;
        $newPath = $this->calculateNewPath($book, $mappedPrimary);

        if ($oldPath !== $newPath) {
            $this->line("  Moving: {$oldPath} → {$newPath}");

            // Handle paths that already contain the base
            if (str_starts_with($oldPath, '/media/')) {
                $fullOldPath = $oldPath;
            } else {
                $fullOldPath = '/media/lyra_data1/audiobooks/books/' . $oldPath;
            }

            $fullNewPath = '/media/lyra_data1/audiobooks/books/' . $newPath;

            if (File::exists($fullOldPath)) {
                $newDir = dirname($fullNewPath);
                if (!File::isDirectory($newDir)) {
                    File::makeDirectory($newDir, 0775, true);
                }

                File::move($fullOldPath, $fullNewPath);
                $book->directory_path = $newPath;
                $book->save();

                $this->info("  ✓ Moved successfully");
            } else {
                $this->warn("  ✗ Old path doesn't exist: {$fullOldPath}");
            }
        } else {
            $book->save();
            $this->info("  ✓ Genres updated (no move needed)");
        }

        return true;
    }

    protected function findInOpenAudible(string $title): ?array
    {
        foreach ($this->openAudibleBooks as $book) {
            if (
                stripos($book['title'], $title) !== false ||
                stripos($title, $book['title']) !== false ||
                ($book['title_short'] ?? '') === $title
            ) {
                return $this->parser->normalizeBookData($book);
            }
        }
        return null;
    }

    protected function calculateNewPath(Book $book, string $primaryGenre): string
    {
        $authors = $book->authors->pluck('name')->toArray();
        $authorPath = implode(', ', $authors);

        $parts = [$primaryGenre, $authorPath];

        if ($book->series->isNotEmpty()) {
            $parts[] = $book->series->first()->name;
        }

        $parts[] = $book->title;

        return implode('/', $parts);
    }
}
