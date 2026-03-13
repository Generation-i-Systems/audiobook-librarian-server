<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookSeriesController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * AJAX endpoint for Tom Select: returns series matching query string, or all if no query.
     */
    public function seriesAjax(Request $request)
    {
        $q = $request->input('q', '');
        $documentStore = $this->documentStoreService;
        $series = $documentStore->listSeries();
        if ($q) {
            $series = array_filter($series, function ($item) use ($q) {
                return stripos($item['name'], $q) !== false;
            });
        }
        usort($series, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        $series = array_slice($series, 0, 20);

        /** @phpstan-ignore-next-line arrayValues.list */
        return response()->json(['data' => array_values($series)]);
    }

    /**
     * Rename a series across all books
     */
    public function renameSeries(Request $request)
    {
        $validated = $request->validate([
            'oldName' => 'required|string',
            'newName' => 'required|string',
            'merge' => 'boolean',
        ]);

        $oldName = $validated['oldName'];
        $newName = $validated['newName'];
        $merge = $validated['merge'] ?? false;

        if ($oldName === $newName) {
            return response()->json([
                'success' => false,
                'message' => 'New name must be different from current name.',
            ], 400);
        }

        $newSeriesExists = false;

        try {
            $newSeriesExists = $this->documentStoreService->getSeriesByName($newName) !== null;

            if ($newSeriesExists && !$merge) {
                $oldSeriesBooks = $this->documentStoreService->listBooks(
                    1,
                    1,
                    ['series' => $oldName, 'include_needs_review' => true],
                    false,
                    'title',
                    'asc',
                    true
                );
                $bookCount = (int) ($oldSeriesBooks['total'] ?? 0);

                return response()->json([
                    'success' => false,
                    'warning' => "A series named '{$newName}' already exists with other books.",
                    'old_name' => $oldName,
                    'new_name' => $newName,
                    'book_count' => $bookCount,
                ]);
            }

            // Perform the rename/merge using the service method
            $count = $this->documentStoreService->renameSeries($oldName, $newName);

            if ($newSeriesExists) {
                $message = "Successfully merged series '{$oldName}' into '{$newName}' for {$count} book(s).";
            } else {
                $message = "Successfully renamed series from '{$oldName}' to '{$newName}' for {$count} book(s).";
            }

            return response()->json([
                'success' => true,
                'merged' => $newSeriesExists,
                'count' => $count,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            Log::error('Error renaming series: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while renaming the series: ' . $e->getMessage(),
            ], 500);
        }
    }
}
