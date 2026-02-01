<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Author;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthorController extends Controller
{
    public function toggleFavorite(Request $request, Author $author)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $isFavorite = $request->input('is_favorite');

        if ($isFavorite) {
            $user->favoritedAuthors()->syncWithoutDetaching([$author->id]);
        } else {
            $user->favoritedAuthors()->detach($author->id);
        }

        // Re-fetch the author with the updated favorite status for the current user
        $author = Author::withExists(['favoritedByUsers as isFavorite' => function ($query) use ($user) {
            $query->where('user_id', $user->id);
        }])->find($author->id);

        return response()->json($author);
    }
}
