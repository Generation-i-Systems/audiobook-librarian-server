<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookContribution;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Illuminate\Support\Facades\Log;

class BookContributionController extends Controller
{
    /**
     * Submit a metadata correction for a book.
     * POST /v1/books/{book}/contributions
     */
    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'changes' => 'required|array|min:1',
            'changes.*.original' => 'nullable|string',
            'changes.*.new' => 'nullable|string',
        ]);

        // Reject submissions where no field actually changed
        $hasChange = collect($data['changes'])->contains(
            fn ($change) => ($change['original'] ?? null) !== ($change['new'] ?? null)
        );

        if (!$hasChange) {
            return response()->json(['message' => 'No changes detected.'], 422);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        $contribution = BookContribution::create([
            'book_id' => $book->id,
            'user_id' => $user->id,
            'changes' => $data['changes'],
            'status' => 'pending',
        ]);

        return response()->json([
            'id' => $contribution->id,
            'status' => $contribution->status,
            'created_at' => $contribution->created_at->toISOString(),
        ], 201);
    }

    /**
     * List the authenticated user's contributions.
     * GET /v1/contributions
     */
    public function mine(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $contributions = BookContribution::with('book:id,title')
            ->where('user_id', $user->id)
            ->latest()
            ->get()
            ->map(fn ($contribution) => [
                'id' => $contribution->id,
                'book_id' => $contribution->book_id,
                'book_title' => data_get($contribution, 'book.title'),
                'changes' => $contribution->changes,
                'status' => $contribution->status,
                'reviewer_notes' => $contribution->reviewer_notes,
                'created_at' => $contribution->created_at->toISOString(),
                'reviewed_at' => $contribution->reviewed_at?->toISOString(),
            ]);

        return response()->json(['data' => $contributions]);
    }

    /**
     * Retract a pending contribution (user cancels their own submission).
     * DELETE /v1/contributions/{contribution}
     */
    public function destroy(BookContribution $contribution): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($contribution->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (!$contribution->isPending()) {
            return response()->json(['message' => 'Only pending contributions can be retracted.'], 422);
        }

        $contribution->delete();

        return response()->json(['message' => 'Contribution retracted.']);
    }

    // ── Admin endpoints ────────────────────────────────────────────────────────

    /**
     * List all pending contributions for editorial review.
     * GET /v1/admin/contributions
     */
    public function pending(Request $request): JsonResponse
    {
        $contributions = BookContribution::with(['book:id,title', 'submitter:id,name,email'])
            ->pending()
            ->latest()
            ->paginate(50);

        return response()->json($contributions);
    }

    /**
     * Approve a contribution and apply the changes to the book.
     * POST /v1/admin/contributions/{contribution}/approve
     */
    public function approve(Request $request, BookContribution $contribution): JsonResponse
    {
        if (!$contribution->isPending()) {
            return response()->json(['message' => 'Contribution is not pending.'], 422);
        }

        $data = $request->validate([
            'reviewer_notes' => 'nullable|string|max:1000',
        ]);

        ControllerDatabase::transaction(function () use ($contribution, $data): void {
            $book = $contribution->book;

            if (! $book instanceof Book) {
                throw new \RuntimeException('Contribution book not found.');
            }

            $this->applyChangesToBook($book, $contribution->changes);

            $contribution->update([
                'status' => 'approved',
                'reviewer_notes' => $data['reviewer_notes'] ?? null,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);
        });

        Log::info('Contribution approved', [
            'contribution_id' => $contribution->id,
            'book_id' => $contribution->book_id,
            'reviewer_id' => Auth::id(),
        ]);

        return response()->json(['message' => 'Contribution approved and applied.']);
    }

    /**
     * Reject a contribution.
     * POST /v1/admin/contributions/{contribution}/reject
     */
    public function reject(Request $request, BookContribution $contribution): JsonResponse
    {
        if (!$contribution->isPending()) {
            return response()->json(['message' => 'Contribution is not pending.'], 422);
        }

        $data = $request->validate([
            'reviewer_notes' => 'nullable|string|max:1000',
        ]);

        $contribution->update([
            'status' => 'rejected',
            'reviewer_notes' => $data['reviewer_notes'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return response()->json(['message' => 'Contribution rejected.']);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function applyChangesToBook(Book $book, array $changes): void
    {
        $directFields = ['title', 'description'];
        $updates = [];

        foreach ($changes as $field => $change) {
            $newValue = $change['new'] ?? null;

            match ($field) {
                'title', 'description' => $updates[$field] = $newValue,
                'author' => $this->syncSingleRelation($book, 'authors', Author::class, $newValue),
                'narrator' => $this->syncSingleRelation($book, 'narrators', Narrator::class, $newValue),
                'genre' => $this->syncSingleRelation($book, 'genres', Genre::class, $newValue),
                'series' => $this->applySeries($book, $newValue, null),
                'seriesNumber' => $this->applySeries($book, null, $newValue),
                default => null,
            };
        }

        if (!empty($updates)) {
            $book->update($updates);
        }
    }

    private function syncSingleRelation(Book $book, string $relation, string $modelClass, ?string $name): void
    {
        if (blank($name)) {
            $book->{$relation}()->detach();
            return;
        }

        $model = $modelClass::firstOrCreate(['name' => trim($name)]);
        $book->{$relation}()->sync([$model->id]);
    }

    private function applySeries(Book $book, ?string $seriesName, ?string $seriesNumber): void
    {
        $currentSeries = $book->series()->first();

        if ($seriesName !== null) {
            if (blank($seriesName)) {
                $book->series()->detach();
                return;
            }
            $series = Series::firstOrCreate(['name' => trim($seriesName)]);
            $pivotData = ['series_number' => $seriesNumber ?? data_get($currentSeries, 'pivot.series_number')];
            $book->series()->sync([$series->id => $pivotData]);
        } elseif ($seriesNumber !== null && $currentSeries) {
            $book->series()->updateExistingPivot($currentSeries->getKey(), ['series_number' => $seriesNumber]);
        }
    }
}
