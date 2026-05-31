<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\ListeningEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Illuminate\Support\Facades\Log;

class BookMatchController extends Controller
{
    /**
     * Search for books to match against local library entries.
     *
     * Accepts title, author, series, seriesNumber and returns candidates
     * with full metadata including audio file names for file-based matching.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'        => 'required|string|max:500',
            'author'       => 'nullable|string|max:255',
            'series'       => 'nullable|string|max:255',
            'seriesNumber' => 'nullable|string|max:50',
            'includeFiles' => 'nullable|boolean',
        ]);

        $title        = $validated['title'];
        $author       = $validated['author'] ?? null;
        $series       = $validated['series'] ?? null;
        $seriesNumber = $validated['seriesNumber'] ?? null;
        $includeFiles = $validated['includeFiles'] ?? true;

        $candidates = $this->findCandidates($title, $author, $series, $seriesNumber);

        $results = $candidates->map(function (Book $book) use ($includeFiles, $title, $author, $series, $seriesNumber) {
            $data                    = $this->transformBook($book);
            $data['matchConfidence'] = $this->calculateConfidence($book, $title, $author, $series, $seriesNumber);

            if ($includeFiles) {
                $data['audioFiles'] = $book->chapters->map(function ($chapter) {
                    return [
                        'fileName'      => $chapter->file_name,
                        'format'        => $chapter->format,
                        'duration'      => $chapter->duration,
                        'sizeBytes'     => $chapter->size_bytes,
                        'chapterNumber' => $chapter->chapter_number,
                    ];
                })->values()->all();
            }

            return $data;
        });

        $sorted = $results->sortByDesc('matchConfidence')->values();

        return response()->json([
            'success'    => true,
            'candidates' => $sorted,
            'total'      => $sorted->count(),
        ]);
    }

    /**
     * Get full book details including audio file names for matching.
     */
    public function details(int $bookId): JsonResponse
    {
        $book = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters'])
            ->find($bookId);

        if (! $book) {
            return response()->json([
                'success' => false,
                'error'   => 'Book not found',
            ], 404);
        }

        $data               = $this->transformBook($book);
        $data['audioFiles'] = $book->chapters->map(function ($chapter) {
            return [
                'fileName'      => $chapter->file_name,
                'format'        => $chapter->format,
                'duration'      => $chapter->duration,
                'sizeBytes'     => $chapter->size_bytes,
                'chapterNumber' => $chapter->chapter_number,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'book'    => $data,
        ]);
    }

    /**
     * Reassign events from an old bookId to a new apiId after a match is established.
     */
    public function reassignEvents(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'oldBookId' => 'required|integer',
            'newBookId' => 'required|integer',
        ]);

        $oldBookId = $validated['oldBookId'];
        $newBookId = $validated['newBookId'];

        if (! Book::where('id', $newBookId)->exists()) {
            return response()->json([
                'success' => false,
                'error'   => 'Target book not found',
            ], 404);
        }

        ControllerDatabase::beginTransaction();
        try {
            $updated = ListeningEvent::where('user_id', $user->id)
                ->where('book_id', $oldBookId)
                ->update(['book_id' => $newBookId]);

            ControllerDatabase::commit();

            Log::info('Reassigned events', [
                'user_id'        => $user->id,
                'old_book_id'    => $oldBookId,
                'new_book_id'    => $newBookId,
                'events_updated' => $updated,
            ]);

            return response()->json([
                'success'       => true,
                'eventsUpdated' => $updated,
            ]);
        } catch (\Exception $e) {
            ControllerDatabase::rollBack();
            Log::error('Failed to reassign events', [
                'user_id'     => $user->id,
                'old_book_id' => $oldBookId,
                'new_book_id' => $newBookId,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Failed to reassign events',
            ], 500);
        }
    }

    private function findCandidates(string $title, ?string $author, ?string $series, ?string $seriesNumber)
    {
        $query = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters']);

        // Primary: fuzzy title match
        $query->where(function ($q) use ($title) {
            $q->where('title', 'LIKE', '%' . $title . '%')
                ->orWhere('title', 'LIKE', '%' . $this->normalizeTitle($title) . '%');
        });

        $candidates = $query->limit(50)->get();

        // If we have author info, also search by author to catch title mismatches
        if ($author) {
            $authorCandidates = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters'])
                ->whereHas('authors', function ($q) use ($author) {
                    $authorParts = explode(',', $author);
                    $q->where(function ($sq) use ($authorParts) {
                        foreach ($authorParts as $part) {
                            $sq->orWhere('name', 'LIKE', '%' . trim($part) . '%');
                        }
                    });
                })
                ->where('title', 'LIKE', '%' . explode(' ', $title)[0] . '%')
                ->limit(20)
                ->get();

            $existingIds = $candidates->pluck('id')->all();
            foreach ($authorCandidates as $candidate) {
                if (! in_array($candidate->id, $existingIds)) {
                    $candidates->push($candidate);
                }
            }
        }

        // If we have series info, search by series too
        if ($series) {
            $seriesCandidates = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters'])
                ->whereHas('series', function ($q) use ($series) {
                    $q->where('name', 'LIKE', '%' . $series . '%');
                })
                ->limit(20)
                ->get();

            $existingIds = $candidates->pluck('id')->all();
            foreach ($seriesCandidates as $candidate) {
                if (! in_array($candidate->id, $existingIds)) {
                    $candidates->push($candidate);
                }
            }
        }

        return $candidates;
    }

    private function calculateConfidence(Book $book, string $title, ?string $author, ?string $series, ?string $seriesNumber): float
    {
        $score    = 0.0;
        $maxScore = 0.0;

        // Title match (40% weight)
        $maxScore              += 40;
        $normalizedBookTitle    = $this->normalizeTitle($book->title ?? '');
        $normalizedSearchTitle  = $this->normalizeTitle($title);
        if ($normalizedBookTitle === $normalizedSearchTitle) {
            $score += 40;
        } elseif (str_contains($normalizedBookTitle, $normalizedSearchTitle) || str_contains($normalizedSearchTitle, $normalizedBookTitle)) {
            $score += 30;
        } else {
            similar_text($normalizedBookTitle, $normalizedSearchTitle, $percent);
            $score += ($percent / 100) * 25;
        }

        // Author match (30% weight)
        if ($author) {
            $maxScore      += 30;
            $bookAuthors    = $book->authors->pluck('name')->map(fn ($n) => strtolower(trim($n)))->all();
            $searchAuthors  = array_map(fn ($a) => strtolower(trim($a)), explode(',', $author));

            $authorMatched = false;
            foreach ($searchAuthors as $sa) {
                foreach ($bookAuthors as $ba) {
                    if ($sa === $ba || str_contains($ba, $sa) || str_contains($sa, $ba)) {
                        $authorMatched = true;
                        break 2;
                    }
                }
            }
            $score += $authorMatched ? 30 : 0;
        }

        // Series match (20% weight)
        if ($series) {
            $maxScore         += 20;
            $bookSeries        = $book->series->pluck('name')->map(fn ($n) => strtolower(trim($n)))->all();
            $normalizedSeries  = strtolower(trim($series));

            $seriesMatched = false;
            foreach ($bookSeries as $bs) {
                if ($bs === $normalizedSeries || str_contains($bs, $normalizedSeries) || str_contains($normalizedSeries, $bs)) {
                    $seriesMatched = true;
                    break;
                }
            }
            $score += $seriesMatched ? 20 : 0;
        }

        // Series number match (10% weight)
        if ($seriesNumber && $series) {
            $maxScore += 10;
            foreach ($book->series as $bs) {
                $pivot = $bs->getRelationValue('pivot');
                if ($pivot && $pivot->getAttribute('series_number') == $seriesNumber) {
                    $score += 10;
                    break;
                }
            }
        }

        return round(($score / $maxScore) * 100, 1);
    }

    private function normalizeTitle(string $title): string
    {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9\s]/', '', $title);
        $title = preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }

    private function transformBook(Book $book): array
    {
        return [
            'id'             => $book->id,
            'title'          => $book->title,
            'author'         => $book->authors->pluck('name')->all(),
            'narrator'       => $book->narrators->pluck('name')->all(),
            'series'         => $book->series->map(function ($s) {
                return [
                    'seriesName' => $s->name,
                    'number'     => $s->pivot->series_number ?? null,
                ];
            })->all(),
            'genres'         => $book->genres->pluck('name')->all(),
            'description'    => $book->description,
            'duration'       => $book->duration,
            'audioFileCount' => $book->audio_file_count,
            'coverImage'     => $this->buildCoverImageOutput($book),
            'year'           => $book->year,
        ];
    }

    private function buildCoverImageOutput(Book $book): ?string
    {
        $coverImage = $book->cover_image;

        if ($coverImage === null) {
            return null;
        }

        $coverImage = trim($coverImage);
        if ($coverImage === '') {
            return null;
        }

        if (str_starts_with($coverImage, 'http://') || str_starts_with($coverImage, 'https://')) {
            return $this->normalizeCoverUrl($coverImage);
        }

        return $this->normalizeCoverUrl(request()->getSchemeAndHttpHost() . '/api/v1/books/' . $book->id . '/cover');
    }

    private function normalizeCoverUrl(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }
}
