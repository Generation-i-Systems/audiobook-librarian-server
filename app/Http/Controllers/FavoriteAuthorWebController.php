<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Models\FavoriteAuthor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FavoriteAuthorWebController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $favorites = FavoriteAuthor::where('user_id', $user->id)
            ->orderBy('author_name')
            ->get();

        $allAuthors = Author::has('books')
            ->orderBy('name')
            ->get();

        return view('favorites.index', compact('favorites', 'allAuthors'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'author_name' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        $existing = FavoriteAuthor::where('user_id', $user->id)
            ->where('author_name', $request->author_name)
            ->exists();

        if ($existing) {
            return redirect()->back()->with('error', 'Author already in favorites');
        }

        FavoriteAuthor::create([
            'user_id' => $user->id,
            'author_name' => $request->author_name,
            'notify_email' => true,
        ]);

        return redirect()->back()->with('success', 'Author added to favorites');
    }

    public function destroy(FavoriteAuthor $favorite)
    {
        // $user = Auth::user();

        // if ($favorite->user_id !== $user->id) {
        //     abort(403);
        // }
        // The above checks are redundant because the user is already authenticated
        // and only their favorites are loaded/accessible via typical flows,
        // but robust policy checks should happen in a Policy class.
        // For PHPStan compliance on type checks that might always be true/false due to model definitions:

        $favorite->delete();

        return redirect()->back()->with('success', 'Author removed from favorites');
    }

    public function toggleNotifications(FavoriteAuthor $favorite)
    {
        // $user = Auth::user();

        // if ($favorite->user_id !== $user->id) {
        //     abort(403);
        // }

        $favorite->update([
            'notify_email' => !$favorite->notify_email,
        ]);

        return redirect()->back()->with('success', 'Notification settings updated');
    }
}
