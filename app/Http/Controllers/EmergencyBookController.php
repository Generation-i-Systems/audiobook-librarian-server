<?php

namespace App\Http\Controllers;

use App\Services\OptimizedBookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Emergency book controller that bypasses memory-intensive model traits
 */
class EmergencyBookController extends Controller
{
    protected OptimizedBookService $optimizedBookService;

    public function __construct(OptimizedBookService $optimizedBookService)
    {
        $this->optimizedBookService = $optimizedBookService;
    }

    /**
     * Emergency book index page
     */
    public function index(Request $request)
    {
        // Memory monitoring
        $memoryStart = memory_get_usage();
        Log::info('Emergency book controller start', ['memory_mb' => round($memoryStart / 1024 / 1024, 2)]);

        try {
            // Get pagination parameters
            $page = max(1, (int) $request->get('page', 1));
            $perPage = 10; // Fixed small limit

            // Get filters
            $filters = [];
            if ($request->filled('search')) {
                $filters['search'] = $request->get('search');
            }
            if ($request->filled('author')) {
                $filters['author'] = $request->get('author');
            }
            if ($request->filled('genre_id')) {
                $filters['genre'] = $request->get('genre_id');
            }

            // Get books using optimized service
            $result = $this->optimizedBookService->getBooks($page, $perPage, $filters);
            $books = collect($result['data']);

            // Get filter options
            $genres = $this->optimizedBookService->getUniqueValues('genre');
            $authors = $this->optimizedBookService->getUniqueValues('author');
            $series = []; // Skip series for now

            // Get recent books
            $recentBooks = $this->optimizedBookService->getRecentBooks(3);

            // Create pagination object
            $pagination = new \Illuminate\Pagination\LengthAwarePaginator(
                $books,
                $result['total'],
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            // Memory monitoring
            $memoryEnd = memory_get_usage();
            Log::info('Emergency book controller complete', [
                'memory_end_mb' => round($memoryEnd / 1024 / 1024, 2),
                'memory_used_mb' => round(($memoryEnd - $memoryStart) / 1024 / 1024, 2),
                'books_loaded' => count($books)
            ]);

            return view('books.emergency_index', [
                'books' => $pagination,
                'genres' => $genres,
                'authors' => $authors,
                'series' => $series,
                'recentBooks' => $recentBooks,
                'mainViewType' => 'list', // Force list view for simplicity
                'mainPerPage' => $perPage,
                'currentFilters' => $filters,
            ]);
        } catch (\Exception $e) {
            Log::error('Emergency controller failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->view('books.emergency_error', [
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
