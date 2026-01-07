<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LibraryRepairIssueType;
use App\Models\Book;
use App\Models\LibraryRepairIssue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class LibraryRepairService
{
    public function __construct(
        private BookPathService $bookPathService,
        private AudiobookBayService $audiobookBayService,
        private BookImportService $bookImportService
    ) {
    }

    public function scan(bool $attemptFixes = true, array $issueTypes = []): array
    {
        $selected = $this->normalizeIssueTypes($issueTypes);
        $results = [];

        foreach ($selected as $type) {
            $results[$type->value] = match ($type) {
                LibraryRepairIssueType::MISSING_DIRECTORY => $this->scanMissingDirectories(),
                LibraryRepairIssueType::DUPLICATE_DIRECTORY => $this->scanDuplicateDirectories(),
                LibraryRepairIssueType::ORPHAN_DIRECTORY => $this->scanOrphanDirectories(),
                LibraryRepairIssueType::NESTED_AUDIO => $this->scanNestedAudio($attemptFixes),
                LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY => $this->scanNumberedSuffixDirectories(),
            };
        }

        $this->clearStaleIssues();

        return $results;
    }

    public function rescanIssue(int $issueId): array
    {
        $issue = LibraryRepairIssue::with('book.authors')->find($issueId);

        if (!$issue) {
            return [
                'status' => 'error',
                'message' => 'Issue not found.',
            ];
        }

        return match ($issue->issue_type) {
            LibraryRepairIssueType::MISSING_DIRECTORY->value => $this->rescanMissingDirectoryIssue($issue),
            LibraryRepairIssueType::DUPLICATE_DIRECTORY->value => $this->rescanDuplicateDirectoryIssue($issue),
            LibraryRepairIssueType::ORPHAN_DIRECTORY->value => $this->rescanOrphanDirectoryIssue($issue),
            LibraryRepairIssueType::NESTED_AUDIO->value => $this->rescanNestedAudioIssue($issue),
            LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY->value => $this->rescanNumberedSuffixIssue($issue),
            default => [
                'status' => 'error',
                'message' => 'Unsupported issue type.',
            ],
        };
    }

    public function importMissingDirectoryIssue(int $issueId, string $importPath): array
    {
        $issue = LibraryRepairIssue::with('book.authors')->find($issueId);

        if (!$issue || $issue->issue_type !== LibraryRepairIssueType::MISSING_DIRECTORY->value) {
            return [
                'status' => 'error',
                'message' => 'Issue not found or not a missing directory issue.',
            ];
        }

        $book = $issue->book;

        if (!$book) {
            return [
                'status' => 'error',
                'message' => 'Book not associated with this issue.',
            ];
        }

        $absoluteImportPath = $this->resolveImportSourcePath($importPath);

        if (!File::isDirectory($absoluteImportPath)) {
            return [
                'status' => 'error',
                'message' => "Import path does not exist or is not a directory: {$absoluteImportPath}",
            ];
        }

        $targetRelativePath = trim((string) ($issue->directory_path ?? $book->directory_path), '/');

        if ($targetRelativePath === '') {
            return [
                'status' => 'error',
                'message' => 'Book does not have an assigned directory path.',
            ];
        }

        $targetDirectory = $this->buildFullPath(
            $this->bookPathService->getBookRoot(),
            $targetRelativePath
        );

        try {
            $audiobook = [
                'path' => $absoluteImportPath,
                'title' => $book->title,
                'authors' => $book->authors?->pluck('name')->all() ?? [],
            ];

            $result = $this->bookImportService->moveFilesToLibrary($audiobook, $book, [
                'operation' => 'move',
                'target_directory' => $targetDirectory,
            ]);

            if (!$result) {
                return [
                    'status' => 'error',
                    'message' => 'Failed to import files from the provided path.',
                ];
            }

            $book->directory_exists = true;
            $book->directory_last_checked = now();
            $book->save();

            $this->resolveIssueWithNotes($issue, 'Imported files from provided path.');

            return [
                'status' => 'resolved',
                'message' => 'Files imported and issue resolved.',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Import failed: ' . $e->getMessage(),
            ];
        }
    }

    private function rescanMissingDirectoryIssue(LibraryRepairIssue $issue): array
    {
        $book = $issue->book;

        if (!$book) {
            return [
                'status' => 'error',
                'message' => 'Book not associated with this issue.',
            ];
        }

        $relativePath = trim((string) ($issue->directory_path ?? $book->directory_path), '/');

        if ($relativePath === '') {
            return [
                'status' => 'error',
                'message' => 'Book does not have a directory path assigned.',
            ];
        }

        $fullPath = $this->buildFullPath($this->bookPathService->getBookRoot(), $relativePath);

        if (File::isDirectory($fullPath)) {
            $this->resolveIssueWithNotes($issue, 'Directory found during rescan.');

            return [
                'status' => 'resolved',
                'message' => 'Directory exists at ' . $fullPath,
            ];
        }

        return [
            'status' => 'pending',
            'message' => 'Directory still missing: ' . $fullPath,
        ];
    }

    private function rescanDuplicateDirectoryIssue(LibraryRepairIssue $issue): array
    {
        $directoryPath = (string) $issue->directory_path;

        if ($directoryPath === '') {
            return [
                'status' => 'error',
                'message' => 'Directory path not recorded for issue.',
            ];
        }

        $bookIds = Book::query()
            ->where('directory_path', $directoryPath)
            ->pluck('id');

        if ($bookIds->count() <= 1) {
            $this->resolveIssueWithNotes($issue, 'Directory no longer duplicated.');

            return [
                'status' => 'resolved',
                'message' => 'Only one book references this directory.',
            ];
        }

        $issue->metadata = [
            'book_ids' => $bookIds->all(),
        ];
        $issue->save();

        return [
            'status' => 'pending',
            'message' => 'Directory still referenced by multiple books.',
        ];
    }

    private function rescanOrphanDirectoryIssue(LibraryRepairIssue $issue): array
    {
        $relativePath = trim((string) $issue->directory_path, '/');

        if ($relativePath === '') {
            return [
                'status' => 'error',
                'message' => 'Directory path missing on issue.',
            ];
        }

        $root = $this->bookPathService->getBookRoot();
        $fullPath = $issue->metadata['full_path'] ?? $this->buildFullPath($root, $relativePath);

        $linkedBookExists = Book::query()
            ->where('directory_path', $relativePath)
            ->exists();

        $directoryExists = File::isDirectory($fullPath);

        if ($linkedBookExists || !$directoryExists) {
            $this->resolveIssueWithNotes(
                $issue,
                $linkedBookExists ? 'Directory now linked to a book.' : 'Directory removed from disk.'
            );

            if ($linkedBookExists) {
                $message = 'Directory is no longer orphaned.';
            } else {
                $message = 'Directory removed from filesystem.';
            }

            return [
                'status' => 'resolved',
                'message' => $message,
            ];
        }

        return [
            'status' => 'pending',
            'message' => 'Directory still present on disk without matching book.',
        ];
    }

    private function rescanNestedAudioIssue(LibraryRepairIssue $issue): array
    {
        $book = $issue->book;

        if (!$book) {
            return [
                'status' => 'error',
                'message' => 'Book not associated with this issue.',
            ];
        }

        $relativePath = trim((string) $book->directory_path, '/');

        if ($relativePath === '') {
            return [
                'status' => 'error',
                'message' => 'Book directory path missing.',
            ];
        }

        $root = $this->bookPathService->getBookRoot();
        $fullPath = $this->buildFullPath($root, $relativePath);

        if (!File::isDirectory($fullPath)) {
            return [
                'status' => 'pending',
                'message' => 'Directory not found on disk.',
            ];
        }

        if ($this->directoryHasAudio($fullPath)) {
            $this->resolveIssueWithNotes($issue, 'Root directory now contains audio files.');

            return [
                'status' => 'resolved',
                'message' => 'Audio found in root directory.',
            ];
        }

        $candidate = $this->findSingleNestedAudioDirectory($fullPath);

        if ($candidate === null) {
            return [
                'status' => 'pending',
                'message' => 'Audio remains only in nested folders.',
            ];
        }

        $candidateRelative = trim(Str::after($candidate, $root), '/');

        if (!$this->directoryInUseByOtherBook($candidateRelative, $book->id)) {
            $originalPath = $book->directory_path;
            $book->directory_path = $candidateRelative;
            $book->directory_exists = true;
            $book->directory_last_checked = now();
            $book->save();

            $this->resolveIssueWithNotes(
                $issue,
                'Updated directory_path from ' . $originalPath . ' to ' . $candidateRelative . '.'
            );

            return [
                'status' => 'resolved',
                'message' => 'Book directory updated to nested audio folder.',
            ];
        }

        return [
            'status' => 'pending',
            'message' => 'Nested audio directory belongs to another book.',
        ];
    }

    private function rescanNumberedSuffixIssue(LibraryRepairIssue $issue): array
    {
        $path = trim((string) $issue->directory_path, '/');

        if ($path === '') {
            return [
                'status' => 'error',
                'message' => 'Directory path missing on issue.',
            ];
        }

        $basePath = (string) preg_replace('/_[0-9]{2}$/', '', $path);

        if ($basePath === '' || $basePath === $path) {
            return [
                'status' => 'error',
                'message' => 'Unable to derive base directory.',
            ];
        }

        $baseExists = Book::query()
            ->where('directory_path', $basePath)
            ->exists();

        if ($baseExists) {
            $this->resolveIssueWithNotes($issue, 'Base directory now exists.');

            return [
                'status' => 'resolved',
                'message' => 'Base directory located: ' . $basePath,
            ];
        }

        return [
            'status' => 'pending',
            'message' => 'Base directory still missing for ' . $path,
        ];
    }

    private function scanMissingDirectories(): array
    {
        $created = 0;
        $resolved = 0;
        $root = $this->bookPathService->getBookRoot();

        /** @var EloquentCollection<Book> $books */
        $books = Book::query()
            ->whereNotNull('directory_path')
            ->get();

        foreach ($books as $book) {
            $relativePath = trim((string) $book->directory_path, '/');
            $fullPath = $this->buildFullPath($root, $relativePath);

            if ($relativePath === '') {
                continue;
            }

            if (!File::isDirectory($fullPath)) {
                $issue = $this->createOrUpdateIssue(
                    $book,
                    LibraryRepairIssueType::MISSING_DIRECTORY,
                    $relativePath,
                    []
                );

                if ($issue->wasRecentlyCreated || $issue->wasChanged()) {
                    $created++;
                }

                $this->markBookNeedsReview($book);
            } else {
                $resolved += $this->resolveIssueIfExists(
                    $book->id,
                    LibraryRepairIssueType::MISSING_DIRECTORY,
                    'Directory present during scan.'
                );
            }
        }

        // Also scan for missing books in /media/audiobooks/sync
        $created += $this->scanMissingDirectoriesInSync();

        return [
            'created' => $created,
            'resolved' => $resolved,
            'autoResolved' => 0,
        ];
    }

    private function scanDuplicateDirectories(): array
    {
        $created = 0;
        $resolved = 0;

        $duplicates = Book::query()
            ->select('directory_path', DB::raw('COUNT(*) as total'))
            ->whereNotNull('directory_path')
            ->groupBy('directory_path')
            ->having('total', '>', 1)
            ->pluck('total', 'directory_path');

        $duplicatePaths = $duplicates->keys()->filter(fn ($path) => (string) $path !== '');

        foreach ($duplicatePaths as $path) {
            $books = Book::query()
                ->where('directory_path', $path)
                ->get();

            $issue = $this->createOrUpdateIssue(
                null,
                LibraryRepairIssueType::DUPLICATE_DIRECTORY,
                $path,
                [
                    'book_ids' => $books->pluck('id')->all(),
                ]
            );

            if ($issue->wasRecentlyCreated || $issue->wasChanged()) {
                $created++;
            }

            foreach ($books as $book) {
                $this->markBookNeedsReview($book);
            }
        }

        // Resolve duplicate issues that no longer apply
        $resolved += $this->resolveIssuesWhere(
            LibraryRepairIssueType::DUPLICATE_DIRECTORY,
            fn (LibraryRepairIssue $issue) => !$duplicatePaths->contains($issue->directory_path),
            'Directory no longer referenced by multiple books.'
        );

        return [
            'created' => $created,
            'resolved' => $resolved,
            'autoResolved' => 0,
        ];
    }

    private function scanOrphanDirectories(): array
    {
        $created = 0;
        $resolved = 0;

        $root = $this->bookPathService->getBookRoot();
        $dbPaths = Book::query()
            ->whereNotNull('directory_path')
            ->pluck('directory_path')
            ->map(fn ($path) => trim((string) $path, '/'))
            ->filter()
            ->values()
            ->all();

        $dbPathSet = array_fill_keys($dbPaths, true);
        $directoriesWithAudio = $this->collectDirectoriesWithAudio($root);

        foreach ($directoriesWithAudio as $relativePath => $meta) {
            if (isset($dbPathSet[$relativePath])) {
                $resolved += $this->resolveIssuesForDirectory(
                    $relativePath,
                    LibraryRepairIssueType::ORPHAN_DIRECTORY,
                    'Directory now referenced by a book.'
                );
                continue;
            }

            $issue = $this->createOrUpdateIssue(
                null,
                LibraryRepairIssueType::ORPHAN_DIRECTORY,
                $relativePath,
                [
                    'full_path' => $meta['full_path'],
                ]
            );

            if ($issue->wasRecentlyCreated || $issue->wasChanged()) {
                $created++;
            }
        }

        // Resolve orphan issues for directories that no longer exist
        $resolved += $this->resolveIssuesWhere(
            LibraryRepairIssueType::ORPHAN_DIRECTORY,
            fn (LibraryRepairIssue $issue) => !array_key_exists($issue->directory_path, $directoriesWithAudio),
            'Directory removed from disk or referenced by a book.'
        );

        return [
            'created' => $created,
            'resolved' => $resolved,
            'autoResolved' => 0,
        ];
    }

    private function scanNestedAudio(bool $attemptFixes): array
    {
        $created = 0;
        $resolved = 0;
        $autoResolved = 0;
        $root = $this->bookPathService->getBookRoot();

        /** @var EloquentCollection<Book> $books */
        $books = Book::query()
            ->whereNotNull('directory_path')
            ->get();

        foreach ($books as $book) {
            $relativePath = trim((string) $book->directory_path, '/');

            if ($relativePath === '') {
                continue;
            }

            $fullPath = $this->buildFullPath($root, $relativePath);

            if (!File::isDirectory($fullPath)) {
                continue;
            }

            if ($this->directoryHasAudio($fullPath)) {
                // Resolve existing nested audio issues if directory now contains audio
                $resolved += $this->resolveIssueIfExists(
                    $book->id,
                    LibraryRepairIssueType::NESTED_AUDIO,
                    'Root directory now contains audio files.'
                );
                continue;
            }

            $candidate = $this->findSingleNestedAudioDirectory($fullPath);

            if ($candidate === null) {
                continue;
            }

            $candidateRelative = trim(Str::after($candidate, $root), '/');

            if ($attemptFixes && !$this->directoryInUseByOtherBook($candidateRelative, $book->id)) {
                $originalPath = $book->directory_path;
                $book->directory_path = $candidateRelative;
                $book->directory_exists = true;
                $book->directory_last_checked = now();
                $book->save();

                $issue = $this->createOrUpdateIssue(
                    $book,
                    LibraryRepairIssueType::NESTED_AUDIO,
                    $candidateRelative,
                    [
                        'original_path' => $originalPath,
                        'updated_path' => $candidateRelative,
                    ],
                    autoResolve: true,
                    resolutionNotes: 'Updated directory_path to nested audio directory.'
                );

                if ($issue->auto_resolved) {
                    $autoResolved++;
                } else {
                    $created++;
                }

                continue;
            }

            $issue = $this->createOrUpdateIssue(
                $book,
                LibraryRepairIssueType::NESTED_AUDIO,
                $relativePath,
                []
            );

            if ($issue->wasRecentlyCreated || $issue->wasChanged()) {
                $created++;
            }

            $this->markBookNeedsReview($book);
        }

        return [
            'created' => $created,
            'resolved' => $resolved,
            'autoResolved' => $autoResolved,
        ];
    }

    private function scanNumberedSuffixDirectories(): array
    {
        $created = 0;
        $resolved = 0;

        $suffixBooks = Book::query()
            ->whereNotNull('directory_path')
            ->get()
            ->filter(function (Book $book) {
                $path = (string) ($book->directory_path ?? '');

                return $path !== '' && preg_match('/_[0-9]{2}$/', $path) === 1;
            });

        $activePaths = collect();

        foreach ($suffixBooks as $book) {
            $path = trim((string) $book->directory_path, '/');

            if ($path === '') {
                continue;
            }

            $basePath = (string) preg_replace('/_[0-9]{2}$/', '', $path);

            if ($basePath === '' || $basePath === $path) {
                continue;
            }

            $activePaths->push($path);

            $baseExists = Book::query()
                ->where('directory_path', $basePath)
                ->exists();

            if ($baseExists) {
                $resolved += $this->resolveIssuesForDirectory(
                    $path,
                    LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY,
                    'Base directory now exists.'
                );

                continue;
            }

            $issue = $this->createOrUpdateIssue(
                $book,
                LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY,
                $path,
                []
            );

            if ($issue->wasRecentlyCreated || $issue->wasChanged()) {
                $created++;
            }

            $this->markBookNeedsReview($book);
        }

        $resolved += $this->resolveIssuesWhere(
            LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY,
            fn (LibraryRepairIssue $issue) => !$activePaths->contains($issue->directory_path),
            'Suffix directory no longer present.'
        );

        return [
            'created' => $created,
            'resolved' => $resolved,
            'autoResolved' => 0,
        ];
    }

    private function createOrUpdateIssue(
        ?Book $book,
        LibraryRepairIssueType $issueType,
        ?string $directoryPath,
        array $metadata,
        bool $autoResolve = false,
        ?string $resolutionNotes = null
    ): LibraryRepairIssue {
        $attributes = [
            'book_id' => $book?->id,
            'issue_type' => $issueType->value,
            'directory_path' => $directoryPath,
        ];

        /** @var LibraryRepairIssue $issue */
        $issue = LibraryRepairIssue::query()->firstOrNew($attributes);
        $issue->metadata = $metadata;

        if ($autoResolve) {
            $issue->status = 'resolved';
            $issue->auto_resolved = true;
            $issue->resolved_at = now();
            $issue->resolution_notes = $resolutionNotes;
        } else {
            $issue->status = 'pending';
            $issue->auto_resolved = false;
            $issue->resolved_at = null;
            $issue->resolution_notes = null;
        }

        $issue->save();

        return $issue;
    }

    private function resolveIssueIfExists(
        ?int $bookId,
        LibraryRepairIssueType $issueType,
        string $notes = ''
    ): int {
        $query = LibraryRepairIssue::query()
            ->where('issue_type', $issueType->value)
            ->where('status', '!=', 'resolved');

        if ($bookId !== null) {
            $query->where('book_id', $bookId);
        }

        $issues = $query->get();

        foreach ($issues as $issue) {
            $issue->status = 'resolved';
            $issue->resolved_at = now();
            $issue->resolution_notes = $notes;
            $issue->save();
        }

        return $issues->count();
    }

    private function resolveIssuesForDirectory(
        string $directoryPath,
        LibraryRepairIssueType $issueType,
        string $notes
    ): int {
        $issues = LibraryRepairIssue::query()
            ->where('issue_type', $issueType->value)
            ->where('directory_path', $directoryPath)
            ->where('status', '!=', 'resolved')
            ->get();

        foreach ($issues as $issue) {
            $issue->status = 'resolved';
            $issue->resolved_at = now();
            $issue->resolution_notes = $notes;
            $issue->save();
        }

        return $issues->count();
    }

    private function resolveIssuesWhere(
        LibraryRepairIssueType $issueType,
        callable $predicate,
        string $notes
    ): int {
        $issues = LibraryRepairIssue::query()
            ->where('issue_type', $issueType->value)
            ->where('status', '!=', 'resolved')
            ->get();

        $resolved = 0;

        foreach ($issues as $issue) {
            if ($predicate($issue)) {
                $issue->status = 'resolved';
                $issue->resolved_at = now();
                $issue->resolution_notes = $notes;
                $issue->save();
                $resolved++;
            }
        }

        return $resolved;
    }

    private function markBookNeedsReview(Book $book): void
    {
        $book->needs_review = true;
        $reasons = collect($book->needs_review_reasons ?? []);

        if (!$reasons->contains('library_repair')) {
            $reasons->push('library_repair');
        }

        $book->needs_review_reasons = $reasons->values()->all();
        $book->save();
    }

    private function directoryInUseByOtherBook(string $directoryPath, int $bookId): bool
    {
        return Book::query()
            ->where('directory_path', $directoryPath)
            ->where('id', '!=', $bookId)
            ->exists();
    }

    private function directoryHasAudio(string $directory): bool
    {
        if (!File::isDirectory($directory)) {
            return false;
        }

        $extensions = $this->getAudioExtensions();

        foreach (File::files($directory) as $file) {
            $ext = strtolower($file->getExtension());

            if (in_array($ext, $extensions, true)) {
                return true;
            }
        }

        return false;
    }

    private function findSingleNestedAudioDirectory(string $directory): ?string
    {
        if (!File::isDirectory($directory)) {
            return null;
        }

        $subdirectories = File::directories($directory);
        $candidates = [];

        foreach ($subdirectories as $subdir) {
            if ($this->directoryHasAudio($subdir)) {
                $candidates[] = $subdir;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        return null;
    }

    private function collectDirectoriesWithAudio(string $root, bool $bypassExcludes = false): array
    {
        $results = [];
        $extensions = $this->getAudioExtensions();
        $excludePatterns = $bypassExcludes ? [] : (array) config('bookparser.exclude_dirs', []);

        if (!is_dir($root)) {
            return [];
        }

        $directoryIterator = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $ext = strtolower($item->getExtension());

            if (!in_array($ext, $extensions, true)) {
                continue;
            }

            $dirPath = $item->getPath();
            $normalizedRelative = trim(Str::after($dirPath, $root), '/');

            if (!$bypassExcludes && $this->shouldExcludeDirectory($normalizedRelative, $excludePatterns)) {
                continue;
            }

            if (!isset($results[$normalizedRelative])) {
                $results[$normalizedRelative] = [
                    'full_path' => $dirPath,
                    'audio_files' => 0,
                    'size' => 0,
                ];
            }

            $results[$normalizedRelative]['audio_files']++;
            $results[$normalizedRelative]['size'] += $item->getSize();
        }

        return $results;
    }

    private function shouldExcludeDirectory(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);

            if ($pattern === '') {
                continue;
            }

            if (@preg_match('/' . $pattern . '/i', $path) && preg_match('/' . $pattern . '/i', $path)) {
                return true;
            }
        }

        return false;
    }

    private function buildFullPath(string $root, string $relativePath): string
    {
        return rtrim($root, '/') . '/' . ltrim($relativePath, '/');
    }

    private function resolveIssueWithNotes(LibraryRepairIssue $issue, string $notes): void
    {
        $issue->status = 'resolved';
        $issue->resolved_at = now();
        $issue->resolution_notes = $notes;
        $issue->save();
    }

    private function clearStaleIssues(): void
    {
        $this->resolveIssuesWithMissingBooks();
    }

    private function resolveIssuesWithMissingBooks(): void
    {
        $issues = LibraryRepairIssue::query()
            ->whereNotNull('book_id')
            ->whereDoesntHave('book')
            ->where('status', '!=', 'resolved')
            ->get();

        foreach ($issues as $issue) {
            $issue->status = 'resolved';
            $issue->resolved_at = now();
            $issue->resolution_notes = 'Book removed from catalog.';
            $issue->save();
        }
    }

    private function resolveImportSourcePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $root = $this->bookPathService->getBookRoot();

        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }

    private function getAudioExtensions(): array
    {
        return array_map('strtolower', (array) config('bookparser.extensions', [
            'mp3',
            'm4b',
            'm4a',
            'mp4',
            'ogg',
            'flac',
            'aac',
            'wav',
        ]));
    }

    private function buildAudiobookBaySearchTerms(Book $book): string
    {
        $author = optional($book->authors->first())->name ?? '';
        $title = $book->title ?? '';

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

        return $normalized;
    }

    private function moveFilesFromNestedToParent(string $nestedDir, string $parentDir): void
    {
        if (!File::isDirectory($nestedDir) || !File::isDirectory($parentDir)) {
            return;
        }

        $files = File::allFiles($nestedDir);

        foreach ($files as $file) {
            $filename = $file->getFilename();
            $targetPath = $parentDir . '/' . $filename;

            // Handle file name conflicts
            if (File::exists($targetPath)) {
                $pathInfo = pathinfo($targetPath);
                $counter = 1;
                while (true) {
                    $suffix = '_' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
                    $candidate = ($pathInfo['dirname'] ?? '') . '/' . ($pathInfo['filename'] ?? 'file') . $suffix;
                    if (!empty($pathInfo['extension'])) {
                        $candidate .= '.' . $pathInfo['extension'];
                    }
                    if (!File::exists($candidate)) {
                        $targetPath = $candidate;
                        break;
                    }
                    $counter++;
                }
            }

            File::move($file->getPathname(), $targetPath);
            chmod($targetPath, 0664);
        }
    }

    /**
     * @param array<int,string|LibraryRepairIssueType> $issueTypes
     *
     * @return array<int,LibraryRepairIssueType>
     */
    private function normalizeIssueTypes(array $issueTypes): array
    {
        if (empty($issueTypes)) {
            return LibraryRepairIssueType::cases();
        }

        $normalized = [];

        foreach ($issueTypes as $type) {
            if ($type instanceof LibraryRepairIssueType) {
                $normalized[$type->value] = $type;
                continue;
            }

            $value = strtolower((string) $type);

            foreach (LibraryRepairIssueType::cases() as $case) {
                if ($case->value === $value) {
                    $normalized[$case->value] = $case;
                    break;
                }
            }
        }

        if (empty($normalized)) {
            return LibraryRepairIssueType::cases();
        }

        return array_values($normalized);
    }

    private function scanMissingDirectoriesInSync(): int
    {
        $created = 0;
        $syncPath = config('app.library_repair_sync_path', '/media/audiobooks/sync');

        if (!is_dir($syncPath)) {
            return 0;
        }

        // Get all directories with audio files in the sync directory, bypassing excludes
        $syncDirectories = $this->collectDirectoriesWithAudio($syncPath, true);

        foreach ($syncDirectories as $relativePath => $metadata) {
            $fullPath = $metadata['full_path'];

            // Check if this directory already exists in the main library
            $existingBook = Book::query()
                ->where('directory_path', $relativePath)
                ->first();

            if (!$existingBook) {
                // Create an orphan directory issue for books found in sync
                $this->createOrUpdateIssue(
                    null,
                    LibraryRepairIssueType::ORPHAN_DIRECTORY,
                    $relativePath,
                    [
                        'full_path' => $fullPath,
                        'audio_files' => $metadata['audio_files'],
                        'size' => $metadata['size'],
                        'source' => 'sync_directory',
                    ]
                );
                $created++;
            }
        }

        return $created;
    }
}
