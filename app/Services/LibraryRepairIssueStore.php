<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\LibraryRepairIssue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LibraryRepairIssueStore
{
    private ?bool $libraryRepairIssuesTableExists = null;

    public function listIssues(array $filters = [], int $limit = 50, int $page = 1): array
    {
        if (!$this->ensureTableExists()) {
            return [];
        }

        try {
            $query = LibraryRepairIssue::query()
                ->with([
                    'book.authors' => function ($query): void {
                        $query->select('authors.id', 'authors.name');
                    },
                ]);

            $this->applyFilters($query, $filters);

            return $query
                ->orderByDesc('created_at')
                ->forPage(max(1, $page), max(1, $limit))
                ->get()
                ->map(fn (LibraryRepairIssue $issue) => $this->transformIssue($issue))
                ->toArray();
        } catch (\Throwable $e) {
            Log::error('listLibraryRepairIssues failed: ' . $e->getMessage());

            return [];
        }
    }

    public function countIssues(array $filters = []): int
    {
        if (!$this->ensureTableExists()) {
            return 0;
        }

        try {
            $query = LibraryRepairIssue::query();
            $this->applyFilters($query, $filters);

            return $query->count();
        } catch (\Throwable $e) {
            Log::error('countLibraryRepairIssues failed: ' . $e->getMessage());

            return 0;
        }
    }

    public function getIssue(int $issueId): ?array
    {
        if (!$this->ensureTableExists()) {
            return null;
        }

        try {
            $issue = LibraryRepairIssue::with([
                'book.authors' => function ($query): void {
                    $query->select('authors.id', 'authors.name');
                },
            ])->find($issueId);

            if (!$issue) {
                return null;
            }

            return $this->transformIssue($issue);
        } catch (\Throwable $e) {
            Log::error('getLibraryRepairIssue failed: ' . $e->getMessage());

            return null;
        }
    }

    public function resolveIssue(int $issueId, ?string $resolutionNotes = null): bool
    {
        if (!$this->ensureTableExists()) {
            return false;
        }

        try {
            /** @var LibraryRepairIssue|null $issue */
            $issue = LibraryRepairIssue::with('book')->find($issueId);

            if (!$issue) {
                return false;
            }

            $issue->status = 'resolved';
            $issue->resolution_notes = $resolutionNotes;
            $issue->resolved_at = now();
            $issue->auto_resolved = false;
            $issue->save();

            $this->clearLibraryRepairReason($issue->book);

            return true;
        } catch (\Throwable $e) {
            Log::error('resolveLibraryRepairIssue failed: ' . $e->getMessage(), [
                'issueId' => $issueId,
            ]);

            return false;
        }
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['issue_type'])) {
            $query->where('issue_type', $filters['issue_type']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($search): void {
                $query->where('directory_path', 'like', '%' . $search . '%')
                    ->orWhereHas('book', function (Builder $bookQuery) use ($search): void {
                        $bookQuery->where('title', 'like', '%' . $search . '%');
                    });
            });
        }

        if (!empty($filters['book_id'])) {
            $query->where('book_id', $filters['book_id']);
        }

        $showResolved = $this->normalizeBooleanFilter($filters['show_resolved'] ?? null);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } elseif (!$showResolved) {
            $query->where('status', 'pending');
        }

        if (array_key_exists('auto_resolved', $filters)) {
            $query->where('auto_resolved', $this->normalizeBooleanFilter($filters['auto_resolved']));
        }
    }

    private function normalizeBooleanFilter(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function ensureTableExists(): bool
    {
        if ($this->libraryRepairIssuesTableExists === false) {
            return false;
        }

        if ($this->libraryRepairIssuesTableExists === null) {
            $this->libraryRepairIssuesTableExists = Schema::hasTable('library_repair_issues');

            if (!$this->libraryRepairIssuesTableExists) {
                Log::notice('library_repair_issues table is missing; skipping library repair queries.');

                return false;
            }
        }

        return true;
    }

    private function transformIssue(LibraryRepairIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'issueType' => $issue->issue_type,
            'status' => $issue->status,
            'directoryPath' => $issue->directory_path,
            'metadata' => $issue->metadata ?? [],
            'autoResolved' => (bool) $issue->auto_resolved,
            'resolvedAt' => $issue->resolved_at ? $issue->resolved_at->toIso8601String() : null,
            'resolutionNotes' => $issue->resolution_notes,
            'createdAt' => $issue->created_at ? $issue->created_at->toIso8601String() : null,
            'updatedAt' => $issue->updated_at ? $issue->updated_at->toIso8601String() : null,
            'book' => $issue->book ? [
                'id' => $issue->book->id,
                'title' => $issue->book->title,
                'directoryPath' => $issue->book->directory_path,
                'authors' => $issue->book->authors->pluck('name')->all(),
                'needsReview' => (bool) $issue->book->needs_review,
                'needsReviewReasons' => (array) ($issue->book->needs_review_reasons ?? []),
            ] : null,
        ];
    }

    private function clearLibraryRepairReason(?Book $book): void
    {
        if (!$book) {
            return;
        }

        $reasons = collect($book->needs_review_reasons ?? [])
            ->reject(fn ($reason) => $reason === 'library_repair')
            ->values()
            ->all();

        $book->needs_review_reasons = $reasons;

        if (empty($reasons)) {
            $book->needs_review = false;
        }

        $book->save();
    }
}
