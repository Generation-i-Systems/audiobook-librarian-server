<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BookTagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TagController extends Controller
{
    public function __construct(private readonly BookTagService $bookTagService)
    {
    }

    public function index(): View
    {
        $tags = $this->bookTagService->systemTagsOverview();

        return view('admin.tags.index', ['tags' => $tags]);
    }

    public function edit(string $tag): View
    {
        $books = $this->bookTagService->booksForSystemTag($tag);

        return view('admin.tags.edit', ['tag' => $tag, 'books' => $books]);
    }

    public function update(Request $request, string $tag): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        $this->bookTagService->renameSystemTag($tag, trim($validated['name']));

        return redirect()->route('admin.tags.index')->with('success', 'Tag renamed successfully!');
    }

    public function destroy(string $tag): RedirectResponse
    {
        $this->bookTagService->deleteSystemTag($tag);

        return redirect()->route('admin.tags.index')->with('success', 'Tag deleted successfully!');
    }
}
