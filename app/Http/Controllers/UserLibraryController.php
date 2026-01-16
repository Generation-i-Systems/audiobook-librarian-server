<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
     * Show the user's reading history (completed books).
     */
    public function history()
    {
        /** @var User $user */
        $user = Auth::user();
        $history = $user->bookStatuses()
            ->with('book')
            ->where('status', 'completed')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->get();

        return view('user.library.history', compact('history'));
    }

    /**
     * Show the user's reading goals (queue with target dates).
     */
    public function goals()
    {
        /** @var User $user */
        $user = Auth::user();
        $goals = $user->bookStatuses()
            ->with('book')
            ->whereIn('status', ['queue', 'in_progress'])
            ->whereNotNull('target_date')
            ->orderBy('target_date')
            ->get();

        return view('user.library.goals', compact('goals'));
    }
}
