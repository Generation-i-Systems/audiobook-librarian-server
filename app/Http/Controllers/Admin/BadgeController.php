<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Badge;

class BadgeController extends Controller
{
    public function index()
    {
        $badges = Badge::orderBy('category')->get()->sort(function ($a, $b) {
            // Sort by category first if not already handled
            if ($a->category !== $b->category) {
                return strcmp($a->category, $b->category);
            }
            if ($a->sort_order !== $b->sort_order) {
                return $a->sort_order <=> $b->sort_order;
            }
            // Tier weight
            $tiers = ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4, 'diamond' => 5];
            $weightA = $tiers[$a->tier] ?? 99;
            $weightB = $tiers[$b->tier] ?? 99;
            if ($weightA !== $weightB) {
                return $weightA <=> $weightB;
            }
            return strcmp($a->name, $b->name);
        });

        $badgesWithIcons = $badges->map(function ($badge) {
            $iconPath = "images/badges/{$badge->key}.svg";
            $hasIconFile = file_exists(public_path($iconPath));

            // Create a new property dynamically or transform into array if preferred
            $badge->icon_path = $hasIconFile ? "/{$iconPath}" : null;
            return $badge;
        });

        $badgesByCategory = $badgesWithIcons->groupBy('category');

        return view('admin.badges.index', [
            'badgesByCategory' => $badgesByCategory
        ]);
    }
}
