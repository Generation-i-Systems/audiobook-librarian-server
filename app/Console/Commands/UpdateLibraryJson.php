<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Traits\HandlesLibraryJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UpdateLibraryJson extends Command
{
    use HandlesLibraryJson;

    protected $signature = 'books:update-json
        {--all : Update all books, not just those with existing librarian.json}
        {--book-id= : Update only the book with this ID}
        {--dry-run : Show what would be done without making any changes}
        {--no-confirm : Skip confirmation prompts}
        {--force : Force update even if file is newer than database record}
        {--skip-fields= : Comma-separated list of fields to skip in diff display}';

    protected $description = 'Update librarian.json files from database records';

    protected DocumentStoreServiceInterface $documentStore;

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
    }

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $processAll = $this->option('all');
        $bookId = $this->option('book-id');
        $noConfirm = $this->option('no-confirm');
        $force = $this->option('force');

        if ($bookId) {
            $book = \App\Models\Book::with(['authors', 'narrators', 'genres', 'series', 'publisher'])
                ->find($bookId);

            if (!$book) {
                $this->error("Book with ID {$bookId} not found.");
                return 1;
            }

            $books = [$book->toArray()];
            $this->info(sprintf('Processing single book: %s (ID: %d)', $book->title, $book->id));
        } else {
            $books = $this->getBooks($processAll);
            $this->info(sprintf('Found %d books to process', count($books)));
        }

        if (empty($books)) {
            $this->info('No books to process.');
            return 0;
        }

        $this->info(sprintf('Mode: %s', $dryRun ? 'Dry run (no changes)' : 'Live mode'));

        if (!$bookId && count($books) > 5) {
            $this->info('Sample of books to process:');
            foreach (array_slice($books, 0, 5) as $sample) {
                $this->line(sprintf('  - %s (ID: %s)', $sample['title'] ?? 'Unknown', $sample['id'] ?? 'unknown'));
            }
            $this->line(sprintf('  ... and %d more', count($books) - 5));
        }

        $skipFields = array_filter(explode(',', $this->option('skip-fields') ?? ''));

        $fieldsToCheck = [
            'title',
            'description',
            'authors',
            'narrators',
            'genres',
            'series',
            'publisher',
            'language',
            'release_date',
            'isbn',
            'fileTags',
        ];

        $booksWithChanges = $this->countBooksWithChanges($books, $fieldsToCheck);

        if ($booksWithChanges === 0) {
            $this->info('No field differences detected. All books are already in sync.');
            return 0;
        }

        if ($dryRun) {
            $this->showFieldDifferences($books, $skipFields);
        }

        if (!$noConfirm && !$dryRun) {
            $this->showFieldDifferences($books, $skipFields);

            if (!$this->confirm(sprintf('Update librarian.json for %d book(s) with changes?', $booksWithChanges))) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $success = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar(count($books));
        $bar->start();

        foreach ($books as $book) {
            try {
                $bookId = $book['id'] ?? ($book['_id'] ?? 'unknown');

                $fieldsToCheck = [
                    'title',
                    'description',
                    'authors',
                    'narrators',
                    'genres',
                    'series',
                    'publisher',
                    'language',
                    'release_date',
                    'isbn',
                    'fileTags',
                ];
                $changes = $this->getBookFieldDifferences($book, $fieldsToCheck);

                if (empty($changes)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                if ($dryRun) {
                    $success++;
                    $bar->advance();
                    continue;
                }

                $result = $this->updateLibraryJson($book);

                if ($result === true) {
                    $success++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $bookId = $book['id'] ?? ($book['_id'] ?? 'unknown');
                $this->error(sprintf('Error processing book %s: %s', $bookId, $e->getMessage()));
                Log::error('Error updating librarian.json', [
                    'book_id' => $bookId,
                    'book_data' => $book,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Library JSON update complete!');
        $this->line(sprintf('Processed: %d', count($books)));
        $this->line(sprintf('Success: %d', $success));
        $this->line(sprintf('Skipped: %d', $skipped));
        $this->line(sprintf('Errors: %d', $errors));

        return $errors === 0 ? 0 : 1;
    }

    protected function getBooks(bool $processAll): array
    {
        $query = \App\Models\Book::with(['authors', 'narrators', 'genres', 'series', 'publisher'])
            ->whereNotNull('directory_path')
            ->where('directory_path', '!=', '');

        return $query->get()->map(function ($book) {
            return $book->toArray();
        })->toArray();
    }

    protected function showFieldDifferences(array $books, array $skipFields): void
    {
        $fieldsToShow = [
            'title',
            'description',
            'authors',
            'narrators',
            'genres',
            'series',
            'publisher',
            'language',
            'release_date',
            'isbn',
        ];

        $fieldsToShow = array_filter($fieldsToShow, function ($field) use ($skipFields) {
            return !in_array($field, $skipFields, true);
        });

        $booksWithChanges = 0;
        $maxBooksToShow = 10;

        foreach (array_slice($books, 0, $maxBooksToShow) as $book) {
            $changes = $this->getBookFieldDifferences($book, $fieldsToShow);

            if (!empty($changes)) {
                $booksWithChanges++;
                $this->displayBookChanges($book, $changes);
            }
        }

        if (count($books) > $maxBooksToShow) {
            $this->line('');
            $this->line(sprintf(
                '... and %d more books (showing first %d with changes)',
                count($books) - $maxBooksToShow,
                $maxBooksToShow
            ));
        }

        if ($booksWithChanges === 0) {
            $this->info('No field differences detected.');
        }
    }

    protected function getBookFieldDifferences(
        array $book,
        array $fieldsToShow
    ): array {
        $changes = [];
        $relativePath = $book['directory_path'] ?? $book['directoryPath'] ?? null;

        if (!$relativePath) {
            return [];
        }

        $jsonPath = Storage::disk('books')->path($relativePath . '/librarian.json');

        if (!file_exists($jsonPath)) {
            return ['_new_file' => true];
        }

        $existingJson = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($existingJson)) {
            return ['_invalid_json' => true];
        }

        $newData = $this->prepareBookDataForJson($book);

        foreach ($fieldsToShow as $field) {
            $currentValue = $this->getFieldValue($existingJson, $field);
            $newValue = $this->getFieldValue($newData, $field);

            if (!$this->valuesEqual($currentValue, $newValue)) {
                $changes[$field] = [
                    'current' => $currentValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    protected function displayBookChanges(array $book, array $changes): void
    {
        $this->newLine();
        $this->line('<fg=cyan>Book: ' . ($book['title'] ?? 'Unknown') . ' (ID: ' . ($book['id'] ?? 'unknown') . ')</>');

        if (isset($changes['_new_file'])) {
            $this->line('  <fg=green>[NEW FILE] Will create librarian.json</>');
            return;
        }

        if (isset($changes['_invalid_json'])) {
            $this->line('  <fg=yellow>[INVALID JSON] Will recreate librarian.json</>');
            return;
        }

        foreach ($changes as $field => $change) {
            $this->line('  <fg=yellow>' . $this->formatLabel($field) . ':</>');
            $this->line('    Current:  ' . $this->formatValue($change['current']));
            $this->line('    New:      ' . $this->formatValue($change['new']));
        }
    }

    protected function getFieldValue(array $data, string $field)
    {
        switch ($field) {
            case 'authors':
                return $data['author'] ?? $data['authors'] ?? [];
            case 'narrators':
                return $data['narrator'] ?? $data['narrators'] ?? [];
            case 'genres':
                return $data['genre'] ?? $data['genres'] ?? [];
            case 'series':
                if (empty($data['series'])) {
                    return null;
                }
                $series = $data['series'];
                if (is_array($series)) {
                    // Handle multiple series as an array of "Name #Number"
                    $seriesList = [];
                    foreach ($series as $name => $number) {
                        $seriesList[] = $number ? $name . ' #' . $number : $name;
                    }
                    return $seriesList;
                }
                if (isset($series['name'])) {
                    $number = $series['number_in_series'] ?? '';
                    return [$number ? $series['name'] . ' #' . $number : $series['name']];
                }
                return null;
            case 'publisher':
                return $data['publisher'] ?? null;
            case 'fileTags':
                $tags = $data['fileTags'] ?? null;
                if (is_string($tags)) {
                    $decoded = json_decode($tags, true);
                    return is_array($decoded) ? $decoded : $tags;
                }
                return $tags;
            default:
                return $data[$field] ?? null;
        }
    }

    protected function extractNames(array $items): array
    {
        return array_map(function ($item) {
            return is_array($item) ? ($item['name'] ?? '') : (string) $item;
        }, $items);
    }

    protected function formatLabel(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }

    protected function formatValue($value): string
    {
        if ($value === null) {
            return '<fg=gray>--</>';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '<fg=gray>--</>';
            }
            return implode(', ', array_map(function ($v) {
                return is_string($v) ? $v : json_encode($v);
            }, $value));
        }

        return (string) $value;
    }

    protected function valuesEqual($current, $new): bool
    {
        if (is_array($current) && is_array($new)) {
            sort($current);
            sort($new);
            return $current === $new;
        }

        return $current === $new;
    }

    protected function countBooksWithChanges(array $books, array $fieldsToCheck): int
    {
        $count = 0;
        foreach ($books as $book) {
            $changes = $this->getBookFieldDifferences($book, $fieldsToCheck);
            if (!empty($changes)) {
                $count++;
            }
        }
        return $count;
    }
}
