<?php

namespace App\Console\Commands;

use App\Services\BookImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateLibraryJson extends Command
{
    protected $signature = 'books:generate-json
        {--all : Process all books, not just those missing librarian.json}
        {--book-id= : Process only the book with this ID}
        {--dry-run : Show what would be done without making any changes}
        {--validate : Validate data without making any changes}
        {--force : Skip field confirmation prompt}
        {--skip-fields= : Comma-separated list of fields to skip}';

    protected $fieldsToShow = [
        'title', 'series', 'authors', 'narrators', 'genres',
        'coverImage', 'fileTags', 'description', 'publisher', 'language'
    ];

    protected $description = 'Generate librarian.json files for all books';

    protected BookImportService $importService;

    public function __construct(BookImportService $importService)
    {
        parent::__construct();
        $this->importService = $importService;
    }

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $validateOnly = $this->option('validate');
        $processAll = $this->option('all');
        $bookId = $this->option('book-id');
        $force = $this->option('force');
        $skipFields = array_filter(explode(',', $this->option('skip-fields') ?? ''));

        if ($bookId) {
            // Process a single book
            $book = \App\Models\Book::with(['authors', 'narrators', 'genres', 'series', 'publisher'])
                ->find($bookId);

            if (!$book) {
                $this->error("Book with ID {$bookId} not found.");
                return 1;
            }

            $books = [$book->toArray()];
            $this->info(sprintf('Processing single book: %s (ID: %d)', $book->title, $book->id));
        } else {
            // Process all matching books
            $books = $this->importService->getAllBooks($processAll);
            $this->info(sprintf('Found %d books to process', count($books)));
        }

        if (empty($books)) {
            $this->info('No books to process.');
            return 0;
        }

        // Show what will be done
        $this->info(sprintf('Found %d books to process', count($books)));
        $this->line(sprintf('Mode: %s', $validateOnly ? 'Validation only' : ($dryRun ? 'Dry run (no changes)' : 'Live mode')));

        // Show sample of books that will be processed
        if (!$bookId && count($books) > 5) {
            $this->info('Sample of books to process:');
            foreach (array_slice($books, 0, 5) as $sample) {
                $this->line(sprintf('  - %s (ID: %s)', $sample['title'] ?? 'Unknown', $sample['id'] ?? 'unknown'));
            }
            $this->line(sprintf('  ... and %d more', count($books) - 5));
        }

        // Remove confirmation prompt as it's not helpful for batch operations

        $success = 0;
        $skipped = 0;
        $errors = 0;
        $validationIssues = [];

        $bar = $this->output->createProgressBar(count($books));
        $bar->start();

        foreach ($books as $book) {
            try {
                $bookId = $book['id'] ?? ($book['_id'] ?? 'unknown');

                if ($validateOnly) {
                    // Just validate without generating
                    $validationResult = $this->validateBookData($book);
                    if ($validationResult['valid']) {
                        $success++;
                    } else {
                        $validationIssues[$bookId] = $validationResult['issues'];
                        $errors++;
                    }
                    continue;
                }

                // Generate the JSON preview
                $previewData = $this->importService->previewLibraryJson($book);

                // Show field diffs for the book
                $this->showFieldDiffs($book, $previewData, $skipFields);

                // Generate the actual JSON
                $result = $this->importService->generateLibraryJson($book, $dryRun);

                if ($result) {
                    $success++;
                } else {
                    $errors++;
                }

                // Skip validation during dry run
                if (!$dryRun) {
                    $validationResult = $this->validateGeneratedJson($book);
                    if (!$validationResult['valid']) {
                        $validationIssues[$bookId] = $validationResult['issues'];
                    }
                }
            } catch (\Exception $e) {
                $bookId = $book['id'] ?? ($book['_id'] ?? 'unknown');
                $this->error(sprintf('Error processing book %s: %s', $bookId, $e->getMessage()));
                Log::error('Error generating librarian.json', [
                    'book_id' => $bookId,
                    'book_data' => $book,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Show summary
        $this->info('Library JSON generation complete!');
        $this->line(sprintf('Processed: %d', count($books)));
        $this->line(sprintf('Success: %d', $success));
        $this->line(sprintf('Skipped: %d', $skipped));
        $this->line(sprintf('Errors: %d', $errors));

        // Show validation issues if any
        if (!empty($validationIssues)) {
            $this->newLine();
            $this->warn('Validation issues found:');
            foreach ($validationIssues as $bookId => $issues) {
                $this->line(sprintf('  Book ID %s:', $bookId));
                foreach ($issues as $issue) {
                    $this->line(sprintf('    - %s', $issue));
                }
            }
        }

        return $errors === 0 ? 0 : 1;
    }

    protected function showFieldDiffs(array $book, array $previewData, array $skipFields): void
    {
        $fields = array_filter($this->fieldsToShow, function ($field) use ($skipFields) {
            return !in_array($field, $skipFields, true);
        });

        if (empty($previewData) || empty($fields)) {
            return;
        }

        $hasChanges = false;
        $title = $book['title'] ?? ($previewData['title'] ?? 'Unknown');

        foreach ($fields as $field) {
            $current = $this->getCurrentFieldValue($book, $field);
            $generated = $this->getGeneratedFieldValue($previewData, $field);

            if ($this->valuesEqual($current, $generated)) {
                continue;
            }

            if (!$hasChanges) {
                $this->line('');
                $this->line('Book: ' . $title);
                $hasChanges = true;
            }

            $this->line('  ' . $this->formatLabel($field) . ':');
            $this->line('    Current: ' . $this->formatValue($current));
            $this->line('    Generated: ' . $this->formatValue($generated));
        }
    }

    protected function validateBookData(array $book): array
    {
        $issues = [];

        if ($this->importService->resolveBookDirectoryPath($book) === null) {
            $issues[] = 'Directory is missing';
        }

        $title = trim((string)($book['title'] ?? ''));
        if ($title === '') {
            $issues[] = 'Missing title';
        }

        if (empty($this->mapNames($book['authors'] ?? []))) {
            $issues[] = 'Missing authors';
        }

        if (empty($this->mapNames($book['genres'] ?? []))) {
            $issues[] = 'Missing genres';
        }

        $audioCount = (int)($book['audio_file_count'] ?? 0);
        if ($audioCount <= 0) {
            $issues[] = 'Missing audio file count';
        }

        $language = trim((string)($book['language'] ?? ''));
        if ($language === '') {
            $issues[] = 'Missing language';
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
        ];
    }

    protected function validateGeneratedJson(array $book): array
    {
        $issues = [];
        $directory = $this->importService->resolveBookDirectoryPath($book);

        if ($directory === null) {
            $issues[] = 'Directory is missing';

            return [
                'valid' => false,
                'issues' => $issues,
            ];
        }

        $jsonPath = rtrim($directory, '/') . '/librarian.json';

        if (!file_exists($jsonPath)) {
            $issues[] = 'librarian.json not found';

            return [
                'valid' => false,
                'issues' => $issues,
            ];
        }

        $contents = file_get_contents($jsonPath);

        if ($contents === false) {
            $issues[] = 'Unable to read librarian.json';

            return [
                'valid' => false,
                'issues' => $issues,
            ];
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            $issues[] = 'Invalid JSON structure';

            return [
                'valid' => false,
                'issues' => $issues,
            ];
        }

        $requiredKeys = ['title', 'author', 'genre', 'directoryPath'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                $issues[] = 'Missing ' . $key . ' in librarian.json';
                continue;
            }

            $value = $data[$key];

            if (is_array($value)) {
                if (empty($value)) {
                    $issues[] = 'Missing ' . $key . ' in librarian.json';
                }
            } else {
                if (trim((string)$value) === '') {
                    $issues[] = 'Missing ' . $key . ' in librarian.json';
                }
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
        ];
    }

    protected function getCurrentFieldValue(array $book, string $field)
    {
        switch ($field) {
            case 'title':
                return $book['title'] ?? null;
            case 'series':
                $series = $book['series'] ?? [];
                $result = [];

                foreach ($series as $item) {
                    $name = is_array($item) ? ($item['name'] ?? null) : null;

                    if (!$name) {
                        continue;
                    }

                    $number = null;

                    if (isset($item['pivot']) && is_array($item['pivot'])) {
                        $number = $item['pivot']['series_number'] ?? $item['pivot']['number_in_series'] ?? null;
                    }

                    if ($number === null) {
                        $number = $item['series_number'] ?? $item['number_in_series'] ?? null;
                    }

                    if ($number !== null) {
                        $formatted = is_numeric($number) ? str_pad((string)$number, 2, '0', STR_PAD_LEFT) : (string)$number;
                        $result[] = $name . ' #' . $formatted;
                    } else {
                        $result[] = $name;
                    }
                }

                return $result;
            case 'authors':
                return $this->mapNames($book['authors'] ?? []);
            case 'narrators':
                return $this->mapNames($book['narrators'] ?? []);
            case 'genres':
                return $this->mapNames($book['genres'] ?? []);
            case 'coverImage':
                return $book['cover_image'] ?? ($book['coverImage'] ?? null);
            case 'fileTags':
                $tags = $book['file_tags'] ?? ($book['fileTags'] ?? null);

                if (is_string($tags)) {
                    $decoded = json_decode($tags, true);

                    if (is_array($decoded)) {
                        $tags = $decoded;
                    }
                }

                if ($tags instanceof \stdClass) {
                    $tags = (array)$tags;
                }

                if (!is_array($tags)) {
                    return $tags;
                }

                ksort($tags);

                return $tags;
            case 'description':
                return $book['description'] ?? null;
            case 'publisher':
                if (is_array($book['publisher'] ?? null)) {
                    return $book['publisher']['name'] ?? null;
                }

                return $book['publisher'] ?? null;
            case 'language':
                return $book['language'] ?? null;
        }

        return null;
    }

    protected function getGeneratedFieldValue(array $previewData, string $field)
    {
        switch ($field) {
            case 'title':
                return $previewData['title'] ?? null;
            case 'series':
                $series = $previewData['series'] ?? [];

                if ($series instanceof \stdClass) {
                    $series = (array)$series;
                }

                if (!is_array($series)) {
                    return [];
                }

                $result = [];

                foreach ($series as $name => $number) {
                    $nameString = is_string($name) ? $name : (string)$name;
                    $numberString = trim((string)$number);

                    if ($numberString === '') {
                        $result[] = $nameString;
                    } else {
                        $result[] = $nameString . ' #' . $numberString;
                    }
                }

                return $result;
            case 'authors':
                return $this->mapSimpleList($previewData['author'] ?? []);
            case 'narrators':
                return $this->mapSimpleList($previewData['narrator'] ?? []);
            case 'genres':
                return $this->mapSimpleList($previewData['genre'] ?? []);
            case 'coverImage':
                return $previewData['coverImage'] ?? null;
            case 'fileTags':
                $tags = $previewData['fileTags'] ?? [];

                if ($tags instanceof \stdClass) {
                    $tags = (array)$tags;
                }

                if (!is_array($tags)) {
                    return $tags;
                }

                ksort($tags);

                return $tags;
            case 'description':
                return $previewData['description'] ?? null;
            case 'publisher':
                return $previewData['publisher'] ?? null;
            case 'language':
                return $previewData['language'] ?? null;
        }

        return null;
    }

    protected function formatLabel(string $field): string
    {
        return trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', ucfirst($field)));
    }

    protected function formatValue($value): string
    {
        if ($value instanceof \stdClass) {
            $value = (array)$value;
        }

        if ($value === null) {
            return '--';
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? '--' : $trimmed;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '--';
            }

            if ($this->isSequentialArray($value)) {
                $parts = [];

                foreach ($value as $item) {
                    $parts[] = $this->formatValue($item);
                }

                return implode(', ', $parts);
            }

            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = $key . '=' . $this->formatValue($item);
            }

            return implode(', ', $parts);
        }

        return (string)$value;
    }

    protected function valuesEqual($current, $generated): bool
    {
        return $this->normalizeForComparison($current) === $this->normalizeForComparison($generated);
    }

    protected function normalizeForComparison($value)
    {
        if ($value instanceof \stdClass) {
            $value = (array)$value;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeForComparison($item);
            }

            if ($this->isSequentialArray($normalized)) {
                sort($normalized);
            } else {
                ksort($normalized);
            }

            return $normalized;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    protected function isSequentialArray(array $array): bool
    {
        return array_keys($array) === range(0, count($array) - 1);
    }

    protected function mapNames(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $name = null;

            if (is_string($item)) {
                $name = $item;
            } elseif (is_array($item) && isset($item['name'])) {
                $name = $item['name'];
            }

            if ($name === null) {
                continue;
            }

            $name = trim((string)$name);

            if ($name === '') {
                continue;
            }

            $result[] = $name;
        }

        return array_values(array_unique($result));
    }

    protected function mapSimpleList($value): array
    {
        if ($value instanceof \stdClass) {
            $value = (array)$value;
        }

        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $candidate = trim($item);

            if ($candidate === '') {
                continue;
            }

            $result[] = $candidate;
        }

        return array_values(array_unique($result));
    }
}
