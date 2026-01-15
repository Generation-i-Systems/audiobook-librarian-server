<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserRecommendation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class UserLibraryController extends Controller
{
    /**
     * Show the user's book queue.
     */
    public function queue()
    {
        /** @var User $user */
        $user = Auth::user();
        $queuedBooks = $user->queuedBooks()->with('book')->get();

        return view('user.library.queue', compact('queuedBooks'));
    }

    /**
     * Show the user's wishlist.
     */
    public function wishlist()
    {
        /** @var User $user */
        $user = Auth::user();
        $wishlist = $user->bookStatuses()
            ->with('book')
            ->where('status', 'wishlist')
            ->get();

        return view('user.library.wishlist', compact('wishlist'));
    }

    /**
     * Show the user's recommendations inbox.
     */
    public function recommendations()
    {
        /** @var User $user */
        $user = Auth::user();
        $recommendations = UserRecommendation::with(['sender:id,name', 'book:id,title,cover_url'])
            ->where('recipient_id', $user->id)
            ->latest()
            ->get();

        return view('user.library.recommendations', compact('recommendations'));
    }
}
