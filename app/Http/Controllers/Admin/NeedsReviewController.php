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
        $items = $this->documentStoreService->listNeedsReviewBooks($reason, $limit, $page);

        // Approximate total for paginator; if we got a full page, pretend there is at least one more
        $count = count($items);
        $total = ($page - 1) * $limit + $count + ($count === $limit ? 1 : 0);

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
