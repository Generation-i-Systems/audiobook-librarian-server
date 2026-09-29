<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Book;
use App\Models\Imports\ImportDraft;
use App\Services\BookImportService;

/**
 * Turns reviewed metadata into the policy parts of a recommendation: duplicate
 * candidates, destination candidates, policy warnings, and required decisions.
 *
 * Every rule here delegates to the legacy importer (duplicate lookup, directory
 * path generation, conflict detection) so drafts and `book:import` agree. It
 * never returns an absolute server path.
 */
class ImportRecommendationPolicy
{
    public const TARGET_RECOMMENDED = 'recommended';
    public const TARGET_RENAMED = 'renamed';
    public const TARGET_EXISTING_BOOK = 'existing_book';

    public const DECISION_DUPLICATE_ACTION = 'duplicate_action';
    public const DECISION_TARGET = 'target';
    public const DECISION_FILE_OPERATION = 'file_operation';
    public const DECISION_ACKNOWLEDGE_WARNINGS = 'acknowledged_warning_ids';

    /**
     * Warning codes produced here. Recomputed on every derive; all other warnings
     * (observation, enrichment) are kept as they were.
     */
    public const POLICY_WARNING_CODES = [
        'missing_title',
        'missing_author',
        'missing_genre',
        'duplicate_found',
        'target_unavailable',
    ];

    public function __construct(
        private readonly BookImportService $importService,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata contract-shaped metadata
     * @param array<string, mixed> $identifiers e.g. ['isbn' => '...']
     * @param array<int, array<string, mixed>> $otherWarnings non-policy warnings to keep
     * @return array{
     *     duplicate_candidates: array<int, array<string, mixed>>,
     *     target_candidates: array<int, array<string, mixed>>,
     *     warnings: array<int, array<string, mixed>>,
     *     required_decisions: array<int, array<string, mixed>>
     * }
     */
    public function derive(ImportDraft $draft, array $metadata, array $identifiers, array $otherWarnings): array
    {
        $legacy = $this->toLegacyMetadata($metadata, $identifiers);
        $hasTitle = ($legacy['title'] ?? '') !== '';
        $hasAuthor = $legacy['author'] !== [];

        $warnings = $otherWarnings;
        if (!$hasTitle) {
            $warnings[] = $this->warning('missing_title', 'No title was found. Enter a title before approving.');
        }
        if (!$hasAuthor) {
            $warnings[] = $this->warning('missing_author', 'No author was found. Enter an author before approving.');
        }
        if ($legacy['genre'] === []) {
            $warnings[] = $this->warning(
                'missing_genre',
                'No genre was found. Without one the book is filed under "Unknown".'
            );
        }

        $existingBook = $hasTitle && $hasAuthor ? $this->importService->findExistingBook('', $legacy) : null;
        $existingHasAudio = $existingBook !== null && $this->existingBookHasAudio($existingBook);
        $duplicateCandidates = $hasTitle ? $this->duplicateCandidates($legacy, $existingBook) : [];
        if ($existingBook !== null) {
            $warnings[] = $this->warning(
                'duplicate_found',
                'This book may already be in the library as "' . $existingBook->title . '".'
            );
        }

        $duplicateOptions = $this->duplicateOptions($existingBook, $existingHasAudio, $duplicateCandidates !== []);
        $targetCandidates = [];
        if ($hasTitle && $hasAuthor) {
            $targetCandidates = $this->targetCandidates($legacy, $existingBook, $existingHasAudio);
        }
        $recommended = $targetCandidates[0] ?? null;
        if ($recommended !== null && $recommended['id'] === self::TARGET_RECOMMENDED && !$recommended['available']) {
            $warnings[] = $this->warning(
                'target_unavailable',
                'The usual destination folder "' . $recommended['relative_directory'] . '" already has other files.'
            );
        }

        return [
            'duplicate_candidates' => $duplicateCandidates,
            'target_candidates' => $targetCandidates,
            'warnings' => $warnings,
            'required_decisions' => $this->requiredDecisions(
                $draft,
                $duplicateOptions,
                $targetCandidates,
                $warnings,
                $existingBook
            ),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function fileOperationsFor(string $transferMode): array
    {
        return array_values(config('import_drafts.file_operations.' . $transferMode, []));
    }

    public function isTargetOccupied(string $relativeDirectory): bool
    {
        return $this->importService->directoryPathHasRealConflict($relativeDirectory);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $identifiers
     * @return array<string, mixed>
     */
    public function toLegacyMetadata(array $metadata, array $identifiers = []): array
    {
        $series = is_array($metadata['series'] ?? null) ? $metadata['series'] : [];

        return array_filter([
            'title' => is_string($metadata['title'] ?? null) ? $metadata['title'] : '',
            'author' => array_values($metadata['authors'] ?? []),
            'narrator' => array_values($metadata['narrators'] ?? []),
            'series' => $series['name'] ?? null,
            'series_number' => $series['number'] ?? null,
            'genre' => array_values($metadata['genres'] ?? []),
            'isbn' => $identifiers['isbn'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $legacy
     * @return array<int, array<string, mixed>>
     */
    private function duplicateCandidates(array $legacy, ?Book $existingBook): array
    {
        $candidates = [];
        if ($existingBook !== null) {
            $reasons = $this->matchReasons($existingBook, $legacy);
            $candidates[$existingBook->id] = $this->duplicateCandidate($existingBook, $reasons);
        }

        $limit = (int) config('import_drafts.max_duplicate_candidates');
        foreach ($this->importService->findSimilarBooks(['path' => (string) $legacy['title']]) as $similar) {
            if (count($candidates) >= $limit) {
                break;
            }
            $bookId = (int) ($similar['id'] ?? 0);
            if ($bookId === 0 || isset($candidates[$bookId])) {
                continue;
            }
            $candidates[$bookId] = [
                'book_id' => $bookId,
                'title' => (string) ($similar['title'] ?? ''),
                'match_reasons' => ['similar_title'],
                'confidence' => 0.5,
            ];
        }

        return array_values($candidates);
    }

    /**
     * @param array<int, string> $reasons
     * @return array<string, mixed>
     */
    private function duplicateCandidate(Book $book, array $reasons): array
    {
        return [
            'book_id' => $book->id,
            'title' => (string) $book->title,
            'match_reasons' => $reasons,
            'confidence' => in_array('isbn', $reasons, true) ? 0.99 : 0.95,
        ];
    }

    /**
     * @param array<string, mixed> $legacy
     * @return array<int, string>
     */
    private function matchReasons(Book $book, array $legacy): array
    {
        $reasons = [];
        if (!empty($legacy['isbn']) && (string) $book->isbn === (string) $legacy['isbn']) {
            $reasons[] = 'isbn';
        }

        $authorNames = array_map('mb_strtolower', $book->authors()->pluck('name')->all());
        $sameTitle = mb_strtolower(trim((string) $book->title)) === mb_strtolower(trim((string) $legacy['title']));
        $sameAuthor = array_intersect($authorNames, array_map('mb_strtolower', $legacy['author'])) !== [];
        if ($sameTitle && $sameAuthor) {
            $reasons[] = 'title_author';
            if (!empty($legacy['series'])) {
                $reasons[] = 'series';
            }
        }

        return $reasons === [] ? ['title_author'] : $reasons;
    }

    private function existingBookHasAudio(Book $book): bool
    {
        $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root');
        if (!is_string($bookRoot) || $bookRoot === '') {
            return false;
        }
        $directory = $this->importService->resolveExistingBookDirectory($book, $bookRoot);

        return $directory !== null && $this->importService->findAudioFilesInDirectory($directory) !== [];
    }

    /**
     * Mirrors book:import's duplicate prompts: an existing copy with audio offers
     * skip/replace/import-with-new-name; a record without audio is merged into.
     *
     * @return array{options: array<int, string>, default: string}
     */
    private function duplicateOptions(?Book $existingBook, bool $existingHasAudio, bool $hasSimilar): array
    {
        if ($existingBook === null) {
            $options = $hasSimilar ? ['create_new', 'skip'] : ['create_new'];

            return ['options' => $options, 'default' => 'create_new'];
        }
        if ($existingHasAudio) {
            return ['options' => ['skip', 'replace', 'create_new'], 'default' => 'skip'];
        }

        return ['options' => ['merge', 'skip'], 'default' => 'merge'];
    }

    /**
     * @param array<string, mixed> $legacy
     * @return array<int, array<string, mixed>>
     */
    private function targetCandidates(array $legacy, ?Book $existingBook, bool $existingHasAudio): array
    {
        $candidates = [];
        $generated = $this->importService->generateDirectoryPath($legacy, ['include_title' => true]);
        $recommended = $this->relativeDirectory($generated);
        $newBookActions = ['create_new', 'skip'];
        if ($recommended !== null) {
            $available = !$this->isTargetOccupied($recommended);
            $candidates[] = $this->targetCandidate(self::TARGET_RECOMMENDED, $recommended, $available, $newBookActions);

            if (!$available) {
                $renamed = $this->relativeDirectory($this->importService->generateDirectoryPath(
                    $legacy + ['_force_rename_directory' => true],
                    ['include_title' => true]
                ));
                if ($renamed !== null && $renamed !== $recommended) {
                    $candidates[] = $this->targetCandidate(
                        self::TARGET_RENAMED,
                        $renamed,
                        !$this->isTargetOccupied($renamed),
                        $newBookActions
                    );
                }
            }
        }

        $existingDirectory = null;
        if ($existingBook !== null) {
            $existingDirectory = $this->relativeDirectory((string) $existingBook->directory_path);
        }
        if ($existingDirectory !== null) {
            $candidates[] = $this->targetCandidate(
                self::TARGET_EXISTING_BOOK,
                $existingDirectory,
                true,
                $existingHasAudio ? ['replace', 'skip'] : ['merge', 'skip']
            );
        }

        return $candidates;
    }

    /**
     * @param array<int, string> $duplicateActions
     * @return array<string, mixed>
     */
    private function targetCandidate(
        string $id,
        string $relativeDirectory,
        bool $available,
        array $duplicateActions
    ): array {
        return [
            'id' => $id,
            'relative_directory' => $relativeDirectory,
            'available' => $available,
            'duplicate_actions' => $duplicateActions,
        ];
    }

    /**
     * Only book-root-relative directories are ever exposed.
     */
    private function relativeDirectory(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return null;
        }

        return trim($path, '/');
    }

    /**
     * @param array{options: array<int, string>, default: string} $duplicateOptions
     * @param array<int, array<string, mixed>> $targetCandidates
     * @param array<int, array<string, mixed>> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function requiredDecisions(
        ImportDraft $draft,
        array $duplicateOptions,
        array $targetCandidates,
        array $warnings,
        ?Book $existingBook
    ): array {
        $availableTargets = array_values(array_filter(
            $targetCandidates,
            static fn (array $candidate): bool => $candidate['available']
        ));
        $defaultTarget = null;
        foreach ($availableTargets as $candidate) {
            if (in_array($duplicateOptions['default'], $candidate['duplicate_actions'], true)) {
                $defaultTarget = $candidate['id'];
                break;
            }
        }
        $fileOperations = $this->fileOperationsFor($draft->source_mode);

        $decisions = [
            [
                'id' => self::DECISION_DUPLICATE_ACTION,
                'type' => 'duplicate_action',
                'plan_field' => 'duplicate_action',
                'options' => $duplicateOptions['options'],
                'default' => $duplicateOptions['default'],
                'related_book_id' => $existingBook?->id,
            ],
            [
                'id' => self::DECISION_TARGET,
                'type' => 'target',
                'plan_field' => 'target.candidate_id',
                'options' => array_column($availableTargets, 'id'),
                'default' => $defaultTarget,
            ],
            [
                'id' => self::DECISION_FILE_OPERATION,
                'type' => 'file_operation',
                'plan_field' => 'file_operation',
                'options' => $fileOperations,
                'default' => $fileOperations[0] ?? null,
            ],
        ];

        $acknowledge = array_values(array_map(
            static fn (array $warning): string => $warning['id'],
            array_filter($warnings, static fn (array $warning): bool => $warning['requires_acknowledgment'] === true)
        ));
        if ($acknowledge !== []) {
            $decisions[] = [
                'id' => self::DECISION_ACKNOWLEDGE_WARNINGS,
                'type' => 'acknowledgment',
                'plan_field' => 'acknowledged_warning_ids',
                'options' => $acknowledge,
                'default' => [],
            ];
        }

        return $decisions;
    }

    /**
     * @return array<string, mixed>
     */
    private function warning(string $code, string $message): array
    {
        return ['id' => $code, 'code' => $code, 'message' => $message, 'requires_acknowledgment' => false];
    }
}
