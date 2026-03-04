<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Enums\LibraryRepairIssueType;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\SystemSetting;
use App\Services\AudiobookBayService;
use App\Services\BookDeletionService;
use App\Services\BookFilesystemService;
use App\Services\LibraryRepairService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LibraryRepairController extends Controller
{
    private const AUDIO_EXTENSIONS = ['mp3', 'm4b', 'm4a', 'flac', 'ogg', 'opus', 'aac', 'wav', 'wma', 'aiff'];
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(
        private DocumentStoreServiceInterface $documentStore,
        private LibraryRepairService $libraryRepairService,
        private AudiobookBayService $audiobookBayService,
        private BookDeletionService $bookDeletionService,
        private BookFilesystemService $bookFilesystemService
    ) {
    }

    public function index(Request $request): View
    {
        $page = max(1, (int) $request->integer('page', 1));
        $limit = max(1, min(100, (int) $request->integer('limit', 25)));

        $filters = $this->buildFilters($request);

        $issues = $this->prepareIssuesForDisplay(
            $this->documentStore->listLibraryRepairIssues($filters, $limit, $page)
        );
        $total = $this->documentStore->countLibraryRepairIssues($filters);

        $paginator = new LengthAwarePaginator(
            collect($issues),
            $total,
            $limit,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        // Get the last run date for library repair scan
        $lastRunDate = SystemSetting::get('library_repair_last_run');

        // Store the current URL as the last viewed list for redirects after edit/update
        session(['last_admin_list_url' => $request->fullUrl()]);

        return view('admin.library-repair.index', [
            'issues' => $paginator,
            'issueTypes' => $this->issueTypeOptions(),
            'lastRunDate' => $lastRunDate,
            'filters' => [
                'issue_type' => $filters['issue_type'] ?? '',
                'search' => $filters['search'] ?? '',
                'limit' => $limit,
                'show_resolved' => $filters['show_resolved'] ?? false,
            ],
        ]);
    }

    public function resolve(Request $request, int $issueId): RedirectResponse
    {
        $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $notes = $request->input('resolution_notes');
        $resolved = $this->documentStore->resolveLibraryRepairIssue($issueId, $notes);

        if (!$resolved) {
            return redirect()
                ->back()
                ->with('error', 'Unable to resolve library repair issue. It may have already been resolved.');
        }

        return redirect()
            ->back()
            ->with('success', 'Library repair issue resolved.');
    }

    public function rescan(int $issueId): RedirectResponse
    {
        try {
            $result = $this->libraryRepairService->rescanIssue($issueId);

            return redirect()
                ->back()
                ->with($result['status'] === 'resolved' ? 'success' : 'info', $result['message']);
        } catch (\Throwable $e) {
            Log::error('library-repair.rescan failed', [
                'issueId' => $issueId,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'Unable to rescan issue: ' . $e->getMessage());
        }
    }

    public function importMissingDirectory(Request $request, int $issueId): RedirectResponse
    {
        $request->validate([
            'import_path' => ['required', 'string'],
        ]);

        try {
            $result = $this->libraryRepairService->importMissingDirectoryIssue(
                $issueId,
                $request->input('import_path')
            );

            return redirect()
                ->back()
                ->with($result['status'] === 'resolved' ? 'success' : 'info', $result['message']);
        } catch (\Throwable $e) {
            Log::error('library-repair.import-missing failed', [
                'issueId' => $issueId,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'Unable to import directory: ' . $e->getMessage());
        }
    }

    public function refresh(): RedirectResponse
    {
        try {
            // Dispatch the library repair scan command to run in the background
            \Illuminate\Support\Facades\Artisan::call('library:repair-scan', [
                '--no-attempt-fixes' => false,
            ]);

            return redirect()
                ->route('admin.library-repair.index')
                ->with('success', 'Library repair scan started. The page will refresh to show updated results.');
        } catch (\Throwable $e) {
            Log::error('Library repair refresh failed', [
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'Unable to start library repair scan: ' . $e->getMessage());
        }
    }

    public function compare(int $issueId): View|RedirectResponse
    {
        $issue = $this->documentStore->getLibraryRepairIssue($issueId);

        if ($issue === null || ($issue['issueType'] ?? '') !== LibraryRepairIssueType::DUPLICATE_DIRECTORY->value) {
            return redirect()->route('admin.library-repair.index')
                ->with('error', 'Issue not found or not a duplicate directory issue.');
        }

        if (($issue['status'] ?? '') !== 'pending') {
            return redirect()->route('admin.library-repair.index')
                ->with('info', 'This issue is already resolved.');
        }

        $bookIds = $issue['metadata']['book_ids'] ?? [];
        $books = Book::withTrashed()->with(['authors', 'narrators', 'series', 'genres'])
            ->whereIn('id', $bookIds)
            ->get();

        $compareFields = [
            'title' => 'Title',
            'authors' => 'Authors',
            'narrators' => 'Narrators',
            'series' => 'Series',
            'genres' => 'Genres',
            'language' => 'Language',
            'release_date' => 'Release Date',
            'isbn' => 'ISBN',
            'asin' => 'ASIN',
            'duration' => 'Duration',
            'audio_file_count' => 'Audio Files',
            'description' => 'Description',
            'created_at' => 'Created',
            'updated_at' => 'Updated',
        ];

        $fieldValues = [];
        $hasDiff = [];

        foreach ($compareFields as $field => $label) {
            $values = $books->map(function (Book $book) use ($field): string {
                return match ($field) {
                    'authors' => $book->authors->pluck('name')->join(', '),
                    'narrators' => $book->narrators->pluck('name')->join(', '),
                    'series' => $book->series->pluck('name')->join(', '),
                    'genres' => $book->genres->pluck('name')->join(', '),
                    'duration' => $book->duration ? gmdate('H:i:s', $book->duration) : '',
                    'created_at', 'updated_at' => $book->$field?->format('Y-m-d H:i'),
                    'release_date' => $book->release_date?->format('Y-m-d') ?? '',
                    default => (string) ($book->$field ?? ''),
                };
            })->all();

            $fieldValues[$field] = $values;
            $hasDiff[$field] = count(array_unique($values)) > 1;
        }

        $directoryPath = $issue['directoryPath'] ?? '';
        $fileGroups = $this->buildFileGroups($directoryPath);

        return view('admin.library-repair.compare', [
            'issue' => $issue,
            'books' => $books,
            'compareFields' => $compareFields,
            'fieldValues' => $fieldValues,
            'hasDiff' => $hasDiff,
            'fileGroups' => $fileGroups,
        ]);
    }

    public function resolveDuplicate(Request $request, int $issueId): RedirectResponse
    {
        $request->validate([
            'keep_book_id' => ['required', 'integer'],
            'field_sources' => ['nullable', 'array'],
            'field_sources.*' => ['integer'],
            'keep_files' => ['nullable', 'array'],
            'keep_files.*' => ['string'],
        ]);

        $issue = $this->documentStore->getLibraryRepairIssue($issueId);

        if ($issue === null || ($issue['issueType'] ?? '') !== LibraryRepairIssueType::DUPLICATE_DIRECTORY->value) {
            return redirect()->route('admin.library-repair.index')
                ->with('error', 'Issue not found or not a duplicate directory issue.');
        }

        $bookIds = $issue['metadata']['book_ids'] ?? [];
        $keepBookId = (int) $request->input('keep_book_id');

        if (!in_array($keepBookId, $bookIds, true)) {
            return redirect()->back()->with('error', 'The selected book is not part of this duplicate issue.');
        }

        $books = Book::withTrashed()->with(['authors', 'narrators', 'series', 'genres'])
            ->whereIn('id', $bookIds)
            ->get()
            ->keyBy('id');

        $keeper = $books->get($keepBookId);

        if ($keeper === null) {
            return redirect()->back()->with('error', 'Keeper book not found.');
        }

        // Step 1: Merge selected field values into keeper
        $this->applyFieldSources($keeper, $books, $request->input('field_sources', []));

        // Step 2: Move unchecked files to trash
        $directoryPath = $issue['directoryPath'] ?? '';
        if ($directoryPath !== '') {
            $this->trashUncheckedFiles($directoryPath, $request->input('keep_files', []));
        }

        // Step 3: Trash duplicate DB records (DB only — shared directory)
        $errors = [];

        foreach ($bookIds as $bookId) {
            if ((int) $bookId === $keepBookId) {
                continue;
            }

            $result = $this->bookDeletionService->moveToTrash((string) $bookId, false);

            if (!($result['success'] ?? false)) {
                $errors[] = "Book #{$bookId}: " . ($result['error'] ?? 'Unknown error');
            }
        }

        if (!empty($errors)) {
            return redirect()->back()->with('error', 'Some books could not be trashed: ' . implode('; ', $errors));
        }

        // Step 4: Rescan + redirect
        try {
            $this->libraryRepairService->rescanIssue($issueId);
        } catch (\Throwable $e) {
            Log::warning('library-repair.resolve-duplicate: rescan failed after trash', [
                'issueId' => $issueId,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('admin.library-repair.index')
            ->with('success', 'Duplicate resolved. Keeper book updated and duplicates trashed.');
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int,Book> $books
     * @param array<string,mixed> $fieldSources
     */
    private function applyFieldSources(Book $keeper, $books, array $fieldSources): void
    {
        $scalarFields = ['title', 'description', 'language', 'asin', 'release_date', 'isbn'];
        $relationFields = ['authors', 'narrators', 'series', 'genres'];

        $scalarUpdates = [];

        foreach ($scalarFields as $field) {
            $sourceId = isset($fieldSources[$field]) ? (int) $fieldSources[$field] : null;

            if ($sourceId === null || $sourceId === $keeper->id) {
                continue;
            }

            $source = $books->get($sourceId);

            if ($source === null) {
                continue;
            }

            $scalarUpdates[$field] = $source->$field;
        }

        if (!empty($scalarUpdates)) {
            $keeper->forceFill($scalarUpdates)->save();
        }

        foreach ($relationFields as $field) {
            $sourceId = isset($fieldSources[$field]) ? (int) $fieldSources[$field] : null;

            if ($sourceId === null || $sourceId === $keeper->id) {
                continue;
            }

            $source = $books->get($sourceId);

            if ($source === null) {
                continue;
            }

            $this->syncRelation($keeper, $source, $field);
        }
    }

    private function syncRelation(Book $keeper, Book $source, string $field): void
    {
        if ($field === 'series') {
            $syncData = [];
            foreach ($source->series as $series) {
                $seriesNumber = $series->pivot->getAttribute('series_number');
                $syncData[$series->id] = ['series_number' => $seriesNumber];
            }
            $keeper->series()->sync($syncData);
            return;
        }

        $ids = $source->$field->pluck('id')->all();

        match ($field) {
            'authors' => $keeper->authors()->sync($ids),
            'narrators' => $keeper->narrators()->sync($ids),
            'genres' => $keeper->genres()->sync($ids),
            default => null,
        };
    }

    /**
     * @param array<int,string> $keepFiles
     */
    private function trashUncheckedFiles(string $directoryPath, array $keepFiles): void
    {
        $listing = $this->bookFilesystemService->listFiles($directoryPath);

        if (!$listing['exists']) {
            return;
        }

        $directoryPath = trim($directoryPath, '/');
        $timestamp = now()->format('Y-m-d_His');
        $trashItemId = $timestamp . '_files_' . md5($directoryPath);
        $trashDisk = Storage::disk('trash');
        $booksDisk = Storage::disk('books');

        foreach ($listing['files'] as $file) {
            $filename = $file['name'];

            if (in_array($filename, $keepFiles, true)) {
                continue;
            }

            $relativePath = $directoryPath . '/' . $filename;

            if (!$booksDisk->exists($relativePath)) {
                continue;
            }

            $trashDisk->makeDirectory($trashItemId);
            $trashPath = $trashItemId . '/files/' . $filename;

            try {
                $booksDisk->move($relativePath, Storage::disk('trash')->path($trashPath));
            } catch (\Throwable $e) {
                Log::warning('library-repair.resolve-duplicate: failed to trash file', [
                    'file' => $relativePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildFileGroups(string $directoryPath): array
    {
        $groups = ['audio' => [], 'image' => [], 'other' => []];

        if ($directoryPath === '') {
            return $groups;
        }

        $listing = $this->bookFilesystemService->listFiles($directoryPath);

        foreach ($listing['files'] as $file) {
            $ext = strtolower($file['extension'] ?? '');

            if (in_array($ext, self::AUDIO_EXTENSIONS, true)) {
                $groups['audio'][] = $file;
            } elseif (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
                $groups['image'][] = $file;
            } else {
                $groups['other'][] = $file;
            }
        }

        return $groups;
    }

    /**
     * @return array<string,array-key>
     */
    private function issueTypeOptions(): array
    {
        $options = [];

        foreach (LibraryRepairIssueType::cases() as $case) {
            $options[$case->value] = str($case->value)
                ->replace('_', ' ')
                ->headline()
                ->toString();
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(Request $request): array
    {
        $filters = [];

        if ($request->filled('issue_type')) {
            $filters['issue_type'] = $request->input('issue_type');
        }

        if ($request->filled('search')) {
            $filters['search'] = $request->input('search');
        }

        $filters['show_resolved'] = $request->boolean('show_resolved', false);

        if (!$filters['show_resolved']) {
            $filters['status'] = 'pending';
        }

        return $filters;
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     *
     * @return array<int,array<string,mixed>>
     */
    private function prepareIssuesForDisplay(array $issues): array
    {
        return collect($issues)
            ->map(function (array $issue): array {
                $issueType = $issue['issueType'] ?? '';
                $issue['issueLabel'] = str($issueType)->replace('_', ' ')->headline()->toString();
                $issue['isResolved'] = ($issue['status'] ?? '') === 'resolved';
                $issue['audiobookBayUrl'] = $this->buildAudiobookBayUrl($issue);

                return $issue;
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $issue
     */
    private function buildAudiobookBayUrl(array $issue): ?string
    {
        if (($issue['issueType'] ?? '') !== LibraryRepairIssueType::MISSING_DIRECTORY->value) {
            return null;
        }

        if (($issue['status'] ?? '') !== 'pending') {
            return null;
        }

        $book = $issue['book'] ?? null;

        if (!is_array($book) || empty($book['title'])) {
            return null;
        }

        $author = $book['authors'][0] ?? '';
        $title = $book['title']; // Title is verified to be non-empty above
        $raw = trim(implode(' ', array_filter([$author, $title])));

        if ($raw === '') {
            $raw = 'audiobook';
        }

        $normalized = Str::of($raw)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/iu', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            $normalized = 'audiobook';
        }

        return $this->audiobookBayService->buildSearchUrl($normalized);
    }
}
