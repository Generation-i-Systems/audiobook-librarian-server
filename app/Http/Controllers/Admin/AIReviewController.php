<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Author;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use Illuminate\Http\Request;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Illuminate\Support\Facades\Log;

class AIReviewController extends Controller
{
    /**
     * Display books with AI suggestions for review
     */
    public function index(Request $request)
    {
        $query = Book::whereNotNull('ai_suggestions')
                     ->where('ai_processed', false);

        // Filter by confidence level
        if ($request->filled('min_confidence')) {
            $query->where('ai_confidence', '>=', $request->input('min_confidence'));
        }

        if ($request->filled('max_confidence')) {
            $query->where('ai_confidence', '<=', $request->input('max_confidence'));
        }

        $books = $query->with(['authors', 'narrators', 'genres', 'series'])
                      ->orderBy('ai_confidence', 'desc')
                      ->paginate(10);

        return view('admin.ai-review.index', compact('books'));
    }

    /**
     * Show details for a specific book's AI suggestions
     */
    public function show(Book $book)
    {
        if (!$book->ai_suggestions) {
            return redirect()->route('admin.ai-review.index')
                           ->with('error', 'No AI suggestions found for this book.');
        }

        $aiSuggestions = json_decode($book->ai_suggestions, true);

        return view('admin.ai-review.show', compact('book', 'aiSuggestions'));
    }

    /**
     * Apply AI suggestions to a book
     */
    public function apply(Request $request, Book $book)
    {
        if (!$book->ai_suggestions) {
            return response()->json(['error' => 'No AI suggestions found'], 404);
        }

        $aiSuggestions = json_decode($book->ai_suggestions, true);
        $selectedFields = $request->input('fields', []);

        try {
            ControllerDatabase::transaction(function () use ($book, $aiSuggestions, $selectedFields) {
                // Apply selected fields
                if (in_array('title', $selectedFields)) {
                    $book->title = $aiSuggestions['title'];
                }

                if (in_array('year', $selectedFields) && $aiSuggestions['year']) {
                    $book->release_date = $aiSuggestions['year'] . '-01-01';
                }

                if (in_array('description', $selectedFields) && $aiSuggestions['description']) {
                    $book->description = $aiSuggestions['description'];
                }

                if (in_array('publisher', $selectedFields) && $aiSuggestions['publisher']) {
                    $book->publisher = $aiSuggestions['publisher'];
                }

                if (in_array('isbn', $selectedFields) && $aiSuggestions['isbn']) {
                    $book->isbn = $aiSuggestions['isbn'];
                }

                if (in_array('language', $selectedFields) && $aiSuggestions['language']) {
                    $book->language = $aiSuggestions['language'];
                }

                // Handle authors
                if (in_array('authors', $selectedFields) && !empty($aiSuggestions['author'])) {
                    $authorIds = [];
                    foreach ($aiSuggestions['author'] as $authorName) {
                        $author = Author::firstOrCreate(['name' => trim($authorName)]);
                        $authorIds[] = $author->id;
                    }
                    $book->authors()->sync($authorIds);
                }

                // Handle narrators
                if (in_array('narrators', $selectedFields) && !empty($aiSuggestions['narrator'])) {
                    $narratorIds = [];
                    foreach ($aiSuggestions['narrator'] as $narratorName) {
                        $narrator = Narrator::firstOrCreate(['name' => trim($narratorName)]);
                        $narratorIds[] = $narrator->id;
                    }
                    $book->narrators()->sync($narratorIds);
                }

                // Handle genres
                if (in_array('genres', $selectedFields) && !empty($aiSuggestions['genre'])) {
                    $genreIds = [];
                    foreach ($aiSuggestions['genre'] as $genreName) {
                        $genre = Genre::firstOrCreate(['name' => trim($genreName)]);
                        $genreIds[] = $genre->id;
                    }
                    $book->genres()->sync($genreIds);
                }

                // Handle series
                if (in_array('series', $selectedFields) && $aiSuggestions['series']) {
                    $series = Series::firstOrCreate(['name' => trim($aiSuggestions['series'])]);
                    $seriesNumber = $aiSuggestions['series_number'] ?? 1;

                    $book->series()->sync([
                        $series->id => ['series_number' => $seriesNumber]
                    ]);
                }

                // Mark as processed
                $book->ai_processed = true;
                $book->ai_confidence = $aiSuggestions['confidence'];
                $book->ai_processed_at = now();
                $book->ai_suggestions = null; // Clear suggestions after applying
                $book->needs_review = false;
                $book->needs_review_reasons = null;

                $book->save();
            });

            return response()->json(['success' => true, 'message' => 'AI suggestions applied successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to apply suggestions: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reject AI suggestions for a book
     */
    public function reject(Book $book)
    {
        try {
            $book->update([
                'ai_suggestions' => null,
                'ai_processed' => true,
                'ai_confidence' => 0,
                'ai_processed_at' => now(),
                'needs_review' => false,
                'needs_review_reasons' => null,
            ]);

            return response()->json(['success' => true, 'message' => 'AI suggestions rejected']);
        } catch (\Exception $e) {
            Log::error('Failed to reject AI suggestions', [
                'book_id' => $book->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'error' => 'Failed to reject AI suggestions'], 500);
        }
    }

    /**
     * Bulk apply AI suggestions for high-confidence books
     */
    public function bulkApply(Request $request)
    {
        $minConfidence = $request->input('min_confidence', 90);
        $limit = $request->input('limit', 50);

        $books = Book::whereNotNull('ai_suggestions')
                     ->where('ai_processed', false)
                     ->where('ai_confidence', '>=', $minConfidence)
                     ->limit($limit)
                     ->get();

        $appliedCount = 0;

        foreach ($books as $book) {
            try {
                $aiSuggestions = json_decode($book->ai_suggestions, true);
                $this->applyAllSuggestions($book, $aiSuggestions);
                $appliedCount++;
            } catch (\Exception $e) {
                Log::error('Bulk AI apply failed for book ' . $book->id, ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Applied AI suggestions to {$appliedCount} books"
        ]);
    }

    /**
     * Apply all AI suggestions to a book
     */
    protected function applyAllSuggestions(Book $book, array $aiSuggestions)
    {
        ControllerDatabase::transaction(function () use ($book, $aiSuggestions) {
            // Apply basic fields
            $book->title = $aiSuggestions['title'];

            if ($aiSuggestions['year']) {
                $book->release_date = $aiSuggestions['year'] . '-01-01';
            }

            if ($aiSuggestions['description']) {
                $book->description = $aiSuggestions['description'];
            }

            if ($aiSuggestions['publisher']) {
                $book->publisher = $aiSuggestions['publisher'];
            }

            if ($aiSuggestions['isbn']) {
                $book->isbn = $aiSuggestions['isbn'];
            }

            if ($aiSuggestions['language']) {
                $book->language = $aiSuggestions['language'];
            }

            // Apply relationships
            if (!empty($aiSuggestions['author'])) {
                $authorIds = [];
                foreach ($aiSuggestions['author'] as $authorName) {
                    $author = Author::firstOrCreate(['name' => trim($authorName)]);
                    $authorIds[] = $author->id;
                }
                $book->authors()->sync($authorIds);
            }

            if (!empty($aiSuggestions['narrator'])) {
                $narratorIds = [];
                foreach ($aiSuggestions['narrator'] as $narratorName) {
                    $narrator = Narrator::firstOrCreate(['name' => trim($narratorName)]);
                    $narratorIds[] = $narrator->id;
                }
                $book->narrators()->sync($narratorIds);
            }

            if (!empty($aiSuggestions['genre'])) {
                $genreIds = [];
                foreach ($aiSuggestions['genre'] as $genreName) {
                    $genre = Genre::firstOrCreate(['name' => trim($genreName)]);
                    $genreIds[] = $genre->id;
                }
                $book->genres()->sync($genreIds);
            }

            if ($aiSuggestions['series']) {
                $series = Series::firstOrCreate(['name' => trim($aiSuggestions['series'])]);
                $seriesNumber = $aiSuggestions['series_number'] ?? 1;

                $book->series()->sync([
                    $series->id => ['series_number' => $seriesNumber]
                ]);
            }

            // Mark as processed
            $book->ai_processed = true;
            $book->ai_confidence = $aiSuggestions['confidence'];
            $book->ai_processed_at = now();
            $book->ai_suggestions = null;
            $book->needs_review = false;
            $book->needs_review_reasons = null;

            $book->save();
        });
    }
}
