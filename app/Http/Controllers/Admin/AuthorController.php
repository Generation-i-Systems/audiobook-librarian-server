<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Author;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $query = Author::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        $authors = $query->orderBy('name')->paginate(20);
        return view('admin.authors.index', compact('authors', 'search'));
    }

    public function create()
    {
        return view('admin.authors.create');
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:authors|max:255']);

        Author::create(['name' => $request->name]);

        return redirect()->route('admin.authors.index')->with('success', 'Author created!');
    }

    public function edit(Author $author)
    {
        return view('admin.authors.edit', compact('author'));
    }

    public function update(Request $request, Author $author)
    {
        $request->validate(['name' => 'required|string|unique:authors,name,' . $author->id . '|max:255']);

        $author->update(['name' => $request->name]);

        return redirect()->route('admin.authors.index')->with('success', 'Author updated!');
    }

    public function destroy(Author $author)
    {
        $author->delete();

        return redirect()->route('admin.authors.index')->with('success', 'Author deleted!');
    }
}
