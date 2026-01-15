<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserBookStatus;
use App\Models\UserRecommendation;

class SocialController extends Controller
{
    /**
     * Show the admin dashboard for user statuses and recommendations.
     */
    public function index()
    {
        $pendingRecommendations = UserRecommendation::with(['sender', 'recipient', 'book'])
            ->whereNull('acknowledged_at')
            ->latest()
            ->limit(50)
            ->get();

        $recentlyCompleted = UserBookStatus::with(['user', 'book'])
            ->where('status', 'completed')
            ->latest('completed_at')
            ->limit(50)
            ->get();

        // Pass a list of valid statuses for any filtering UI (assuming a full implementation needs this)
        $validStatuses = [
            'queue' => 'Reading Queue',
            'wishlist' => 'Wishlist',
            'completed' => 'Completed',
            'in_progress' => 'In Progress',
            'paused' => 'Paused',
            'dropped' => 'Dropped',
        ];

        return view('admin.social.index', compact(
            'pendingRecommendations',
            'recentlyCompleted',
            'validStatuses'
        ));
    }
}
