<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $firestore = new FirestoreService();
        $authors = $firestore->listAuthors();
        if ($search) {
            $authors = array_filter($authors, function ($author) use ($search) {
                return stripos($author['name'], $search) !== false;
            });
        }
        // Optionally sort authors by name
        usort($authors, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return view('admin.authors.index', ['authors' => $authors, 'search' => $search]);
    }

    public function create()
    {
        return view('admin.authors.create');
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $firestore = new FirestoreService();
        // Check for duplicate
        foreach ($firestore->listAuthors() as $author) {
            if (strcasecmp($author['name'], $request->name) === 0) {
                return redirect()->route('admin.authors.index')->withErrors(['name' => 'Author already exists!']);
            }
        }
        $firestore->createAuthor(['name' => $request->name]);

        return redirect()->route('admin.authors.index')->with('success', 'Author created!');
    }

    public function edit($id)
    {
        $firestore = new FirestoreService();
        $author = $firestore->getAuthor($id);
        if (! $author) {
            abort(404);
        }

        return view('admin.authors.edit', compact('author'));
    }

    public function update(Request $request, $id)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $firestore = new FirestoreService();
        $author = $firestore->getAuthor($id);
        if (! $author) {
            abort(404);
        }
        // Check for duplicate name
        foreach ($firestore->listAuthors() as $a) {
            if (strcasecmp($a['name'], $request->name) === 0 && $a['id'] !== $id) {
                return redirect()->route('admin.authors.index')->withErrors(['name' => 'Author already exists!']);
            }
        }
        $firestore->updateAuthor($id, ['name' => $request->name]);

        return redirect()->route('admin.authors.index')->with('success', 'Author updated!');
    }

    public function destroy($id)
    {
        $firestore = new FirestoreService();
        $firestore->deleteAuthor($id);

        return redirect()->route('admin.authors.index')->with('success', 'Author deleted!');
    }

    /**
     * AJAX endpoint for Tom Select: returns authors matching query string, or all if no query.
     */
    public function ajax(Request $request)
    {
        $q = $request->input('q', '');
        $firestore = new FirestoreService();
        $authors = $firestore->listAuthors();
        if ($q) {
            $authors = array_filter($authors, function ($author) use ($q) {
                return stripos($author['name'], $q) !== false;
            });
        }
        // Limit and sort
        $authors = array_slice($authors, 0, 20);
        usort($authors, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return response()->json(['data' => array_values($authors)]);
    }
}
