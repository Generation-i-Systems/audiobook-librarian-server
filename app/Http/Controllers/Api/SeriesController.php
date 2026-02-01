<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Series;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SeriesController extends Controller
{
    public function toggleFavorite(Request $request, Series $series)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $isFavorite = $request->input('is_favorite');

        if ($isFavorite) {
            $user->favoritedSeries()->syncWithoutDetaching([$series->id]);
        } else {
            $user->favoritedSeries()->detach($series->id);
        }

        // Re-fetch the series with the updated favorite status for the current user
        $series = Series::withExists(['favoritedByUsers as isFavorite' => function ($query) use ($user) {
            $query->where('user_id', $user->id);
        }])->find($series->id);

        return response()->json($series);
    }
}
