<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BookTagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemTagController extends Controller
{
    public function __construct(private readonly BookTagService $bookTagService)
    {
    }

    public function index(Request $request): View
    {
        $tags = $this->bookTagService->systemTagsOverview();

        $tag = trim((string) $request->query('tag', ''));
        $search = trim((string) $request->query('search', ''));
        $genre = (int) $request->query('genre', 0);
        $genres = \App\Models\Genre::query()->orderBy('name')->get(['id', 'name']);
        $books = $this->bookTagService->browseSystemTags($tag, $search, $genre);

        return view('tags.index', compact('tags', 'books', 'tag', 'search', 'genres', 'genre'));
    }

    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'book_ids' => ['required', 'array', 'min:1', 'max:50'],
            'book_ids.*' => ['required', 'integer', 'distinct', 'exists:books,id'],
            'tag' => ['required', 'string', 'max:64', 'regex:/\S/'],
            'action' => ['required', 'in:add,remove'],
        ]);
        $this->bookTagService->bulkSystemTags($request->user(), $validated['book_ids'], trim($validated['tag']), $validated['action']);

        return back()->with('success', __('Tags updated successfully!'));
    }

    public function edit(string $tag): View
    {
        $books = $this->bookTagService->booksForSystemTag($tag);

        return view('tags.edit', ['tag' => $tag, 'books' => $books]);
    }

    public function update(Request $request, string $tag): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        $this->bookTagService->renameSystemTag($tag, trim($validated['name']));

        return redirect()->route('tags.index')->with('success', __('Tag renamed successfully!'));
    }

    public function destroy(string $tag): RedirectResponse
    {
        $this->bookTagService->deleteSystemTag($tag);

        return redirect()->route('tags.index')->with('success', __('Tag deleted successfully!'));
    }
}
