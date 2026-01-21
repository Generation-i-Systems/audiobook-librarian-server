<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookDeletionService;
use Illuminate\Http\Request;

class TrashController extends Controller
{
    public function __construct(
        private BookDeletionService $deletionService
    ) {
    }

    public function index(Request $request)
    {
        $page = (int) $request->get('page', 1);
        $perPage = 50;

        $trashData = $this->deletionService->listTrashItems($page, $perPage);
        $stats = [
            'total_count' => $this->deletionService->getTotalTrashCount(),
            'total_size' => $this->deletionService->getTotalTrashSize(),
        ];

        // Store the current URL as the last viewed list for redirects after edit/update
        session(['last_admin_list_url' => $request->fullUrl()]);

        return view('admin.trash.index', [
            'items' => $trashData['items'],
            'total' => $trashData['total'],
            'current_page' => $trashData['current_page'],
            'total_pages' => $trashData['total_pages'],
            'per_page' => $trashData['per_page'],
            'stats' => $stats,
        ]);
    }

    public function restore(string $id)
    {
        $result = $this->deletionService->restore($id);

        if ($result) {
            return redirect()->route('admin.trash.index')
                ->with('success', 'Book restored successfully.');
        }

        return redirect()->route('admin.trash.index')
            ->with('error', 'Failed to restore book.');
    }

    public function destroy(string $id)
    {
        $result = $this->deletionService->permanentDelete($id);

        if ($result) {
            return redirect()->route('admin.trash.index')
                ->with('success', 'Book permanently deleted.');
        }

        return redirect()->route('admin.trash.index')
            ->with('error', 'Failed to delete book.');
    }

    public function destroyAll()
    {
        $count = $this->deletionService->permanentDeleteAll();

        return redirect()->route('admin.trash.index')
            ->with('success', "Permanently deleted {$count} item(s).");
    }

    public function applyAutoCleanup(Request $request)
    {
        $days = (int) $request->input('days', 90);
        $count = $this->deletionService->applyAutoCleanup($days);

        return redirect()->route('admin.trash.index')
            ->with('success', "Auto-cleanup removed {$count} item(s) older than {$days} days.");
    }
}
