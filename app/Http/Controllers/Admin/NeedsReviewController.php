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

        // Get total count for accurate pagination
        $total = $this->documentStoreService->countNeedsReviewBooks($reason);

        // Fetch items for current page
        $items = $this->documentStoreService->listNeedsReviewBooks($reason, $limit, $page);

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

        // Store the current URL as the last viewed list for redirects after edit/update
        session(['last_admin_list_url' => $request->fullUrl()]);

        return view('admin.needs_review.index', [
            'books' => $paginator,
            'reasons' => $reasons,
            'selectedReason' => $reason,
            'limit' => $limit,
        ]);
    }
}
