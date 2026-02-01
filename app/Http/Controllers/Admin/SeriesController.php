<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SeriesController extends Controller
{
    protected $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function edit($id, Request $request)
    {
        try {
            $series = $this->documentStoreService->getSeries($id);
            if (!$series) {
                abort(404, 'Series not found');
            }

            $page = max(1, (int) $request->input('page', 1));
            $perPage = 25;

            $books = $this->documentStoreService->getBooksInSeries($id);
            $total = count($books);
            $totalPages = (int) ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            $paginatedBooks = array_slice($books, $offset, $perPage);

            return view('admin.series.edit', [
                'series' => $series,
                'books' => $paginatedBooks,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'perPage' => $perPage,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to load series for editing: ' . $e->getMessage());
            return redirect()->route('series.manage')->with('error', 'Failed to load series for editing.');
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $series = $this->documentStoreService->getSeries($id);
            if (!$series) {
                abort(404, 'Series not found');
            }

            $this->documentStoreService->updateSeries($id, ['name' => $validated['name']]);
            return redirect()->route('admin.series.edit', $id)->with('success', 'Series updated successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to update series: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to update series. Please try again.')->withInput();
        }
    }

    public function destroy($id)
    {
        try {
            $series = $this->documentStoreService->getSeries($id);
            if (!$series) {
                abort(404, 'Series not found');
            }

            $this->documentStoreService->deleteSeries($id);
            return redirect()->route('series.manage')->with('success', 'Series deleted successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to delete series: ' . $e->getMessage());
            return redirect()->route('series.manage')->with('error', 'Failed to delete series. Please try again.');
        }
    }
}
