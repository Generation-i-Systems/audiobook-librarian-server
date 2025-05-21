<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Http\Request;

class GenreController extends Controller
{
    public function index()
    {
        $firestore = new FirestoreService();
        $genres = $firestore->listGenres();
        return view('admin.genres.index', compact('genres'));
    }

    public function create()
    {
        return view('admin.genres.create');
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $firestore = new FirestoreService();
        // Check for duplicate
        foreach ($firestore->listGenres() as $genre) {
            if (strcasecmp($genre['name'], $request->name) === 0) {
                return redirect()->route('admin.genres.index')->withErrors(['name' => 'Genre already exists!']);
            }
        }
        $firestore->createGenre(['name' => $request->name]);
        return redirect()->route('admin.genres.index')->with('success', 'Genre created!');
    }

    public function edit($id)
    {
        $firestore = new FirestoreService();
        $genre = $firestore->getGenre($id);
        if (!$genre) {
            abort(404);
        }
        return view('admin.genres.edit', compact('genre'));
    }

    public function update(Request $request, $id)
    {
        $request->validate(['name' => 'required|string|max:255']);
        $firestore = new FirestoreService();
        $genre = $firestore->getGenre($id);
        if (!$genre) {
            abort(404);
        }
        // Check for duplicate name
        foreach ($firestore->listGenres() as $g) {
            if (strcasecmp($g['name'], $request->name) === 0 && $g['id'] !== $id) {
                return redirect()->route('admin.genres.index')->withErrors(['name' => 'Genre already exists!']);
            }
        }
        $firestore->updateGenre($id, ['name' => $request->name]);
        return redirect()->route('admin.genres.index')->with('success', 'Genre updated!');
    }

    public function destroy($id)
    {
        $firestore = new FirestoreService();
        $firestore->deleteGenre($id);
        return redirect()->route('admin.genres.index')->with('success', 'Genre deleted!');
    }
}
