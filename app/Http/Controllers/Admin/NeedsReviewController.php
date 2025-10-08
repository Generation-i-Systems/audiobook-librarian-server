<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class NeedsReviewController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function index(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = max(1, (int) $request->input('limit', 20));
        $reason = $request->input('reason');

        $reasons = $this->documentStoreService->listNeedsReviewReasons();

        // Fetch one extra item to check if there are more pages
        $items = $this->documentStoreService->listNeedsReviewBooks($reason, $limit + 1, $page);

        $count = count($items);
        $hasMore = $count > $limit;

        // Only show $limit items, trim the extra one
        if ($hasMore) {
            $items = array_slice($items, 0, $limit);
        }

        // Calculate approximate total for paginator
        $total = ($page - 1) * $limit + count($items) + ($hasMore ? 1 : 0);

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $limit,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return view('admin.needs_review.index', [
            'books' => $paginator,
            'reasons' => $reasons,
            'selectedReason' => $reason,
            'limit' => $limit,
        ]);
    }
}
