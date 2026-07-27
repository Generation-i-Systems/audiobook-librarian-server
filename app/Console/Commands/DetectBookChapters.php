<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\BookChapterService;
use App\Services\ChapterDetectionService;
use App\Traits\HandlesLibraryJson;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class DetectBookChapters extends Command
{
    use HandlesLibraryJson;

    protected $signature = 'books:detect-chapters
        {books?* : Book ids to process}
        {--book=* : Book id or inclusive id range to process; may be used more than once}
        {--newest= : Process this many newest local books without chapters unless --refresh is set}
        {--all : Process all local books without chapters unless --refresh is set}
        {--limit= : Stop after this many selected books}
        {--max-load= : Skip or stop when 1-minute system load is at or above this value}
        {--refresh : Redetect embedded chapters even when librarian.json already has chapters}';

    protected $description = 'Detect embedded audio chapters and write them to librarian.json';

    public function __construct(
        private readonly ChapterDetectionService $chapterDetectionService,
        private readonly BookChapterService $bookChapterService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $maxLoad = $this->floatOption('max-load');
        if ($this->isSystemLoadTooHigh($maxLoad)) {
            $this->warn(sprintf(
                'Skipping: 1-minute system load %.2f is at or above threshold %.2f.',
                $this->currentOneMinuteLoad() ?? 0.0,
                $maxLoad
            ));
            return self::SUCCESS;
        }

        $bookIds = $this->selectedBookIds();
        $newest = $this->intOption('newest');
        $limit = $this->intOption('limit');
        $refresh = (bool) $this->option('refresh');

        if ($bookIds === [] && $newest === null && ! $this->option('all')) {
            $this->error('Select books with book ids, --book, --newest, or --all.');
            return self::INVALID;
        }

        $bookLimit = $newest ?? $limit;
        $query = $this->buildBookQuery($bookIds, $newest, $bookLimit, $refresh);
        $processed = 0;
        $skipped = 0;
        $chapterCount = 0;
        $errors = 0;

        foreach ($query->cursor() as $book) {
            if ($bookLimit !== null && $processed >= $bookLimit) {
                break;
            }

            if ($this->isSystemLoadTooHigh($maxLoad)) {
                $this->warn(sprintf(
                    'Stopping: 1-minute system load %.2f is at or above threshold %.2f.',
                    $this->currentOneMinuteLoad() ?? 0.0,
                    $maxLoad
                ));
                break;
            }

            $startedAt = microtime(true);
            $jsonChapters = [];
            if ($refresh) {
                $jsonChapters = $this->chapterDetectionService->detectForDirectory($book->directory_path);
                $this->bookChapterService->replaceBookChapters($book, $jsonChapters, 'embedded');
            } elseif ($book->chapters()->exists()) {
                $jsonChapters = $this->bookChapterService->toJsonChapters($book->chapters()->get());
            } else {
                $jsonChapters = $this->libraryJsonChapters($book);

                if ($jsonChapters !== []) {
                    $this->bookChapterService->importJsonChaptersIfMissing($book, $jsonChapters);
                } else {
                    $jsonChapters = $this->chapterDetectionService->detectForDirectory($book->directory_path);
                    $this->bookChapterService->replaceBookChapters($book, $jsonChapters, 'embedded');
                }
            }

            $bookData = $book->refresh()->load(['authors', 'narrators', 'genres', 'series', 'publisher', 'chapters'])->toArray();

            if (! $this->updateLibraryJson($bookData)) {
                $errors++;
                continue;
            }

            $processed++;
            $chapterCount += count($jsonChapters);

            $this->line(sprintf(
                '%s: %d chapters available in %s',
                $this->bookLabel($book),
                count($jsonChapters),
                $this->formatElapsedTime(microtime(true) - $startedAt)
            ));
        }

        $this->info(sprintf(
            'Processed %d books: %d chapters available, %d skipped, %d errors.',
            $processed,
            $chapterCount,
            $skipped,
            $errors
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<int, int> $bookIds
     * @return Builder<Book>
     */
    private function buildBookQuery(array $bookIds, ?int $newest, ?int $limit, bool $refresh): Builder
    {
        $query = Book::query()
            ->whereNotNull('directory_path')
            ->where(function (Builder $query): void {
                $query->whereNull('source')->orWhere('source', '!=', 'librivox');
            });

        if ($bookIds === [] && ! $refresh) {
            $query->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('chapters')
                    ->whereColumn('chapters.book_id', 'books.id');
            });
        }

        if ($bookIds !== []) {
            $query->whereIn('id', $bookIds)->orderBy('id');
        } elseif ($newest !== null) {
            $query->orderByDesc('created_at');
        } else {
            $query->orderBy('id');
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query;
    }

    private function bookLabel(Book $book): string
    {
        $book->loadMissing('authors');

        $authors = $book->authors
            ->pluck('name')
            ->filter()
            ->implode(', ');

        return sprintf(
            'Book %d - %s - %s',
            $book->id,
            $authors !== '' ? $authors : 'Unknown Author',
            $book->title ?: 'Untitled'
        );
    }

    /**
     * @return array<int, int>
     */
    private function selectedBookIds(): array
    {
        $ids = [];

        foreach (array_merge($this->argument('books'), $this->option('book')) as $selection) {
            if ($selection === null) {
                continue;
            }

            $value = trim((string) $selection);
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $value, $matches) === 1) {
                $start = (int) $matches[1];
                $end = (int) $matches[2];

                foreach (range(min($start, $end), max($start, $end)) as $id) {
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }

                continue;
            }

            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, mixed>
     */
    private function libraryJsonChapters(Book $book): array
    {
        $directoryPath = $book->directory_path;
        if (! is_string($directoryPath) || $directoryPath === '') {
            return [];
        }

        $jsonPath = trim($directoryPath, '/') . '/librarian.json';
        if (! Storage::disk('books')->exists($jsonPath)) {
            return [];
        }

        $decoded = json_decode(Storage::disk('books')->get($jsonPath), true);
        if (! is_array($decoded) || empty($decoded['chapters']) || ! is_array($decoded['chapters'])) {
            return [];
        }

        return $decoded['chapters'];
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function floatOption(string $name): ?float
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function isSystemLoadTooHigh(?float $maxLoad): bool
    {
        $load = $this->currentOneMinuteLoad();

        return $maxLoad !== null && $load !== null && $load >= $maxLoad;
    }

    private function currentOneMinuteLoad(): ?float
    {
        $load = sys_getloadavg();

        return is_array($load) ? (float) $load[0] : null;
    }

    private function formatElapsedTime(float $seconds): string
    {
        if ($seconds < 1.0) {
            return sprintf('%dms', (int) round($seconds * 1000));
        }

        return sprintf('%.2fs', $seconds);
    }
}
