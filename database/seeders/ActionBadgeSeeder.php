<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class ActionBadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = $this->badges();
        $order = 200;
        foreach ($badges as $b) {
            $data = array_merge([
                'description' => '',
                'image_url' => '/images/badges/' . $b['key'] . '.svg',
                'points' => 0,
                'criteria' => [],
                'is_active' => true,
                'is_repeatable' => false,
                'sort_order' => $order++,
            ], $b);

            Badge::updateOrCreate(['key' => $data['key']], $data);
        }
    }

    private function badges(): array
    {
        return [
            [
                'key' => 'action_android_explorer',
                'name' => 'Android Explorer',
                'icon' => '🤖',
                'category' => 'discovery',
                'tier' => 'bronze',
                'description' => 'Install the app on Android',
                'criteria' => ['action_app_installed_android' => 1],
                'points' => 10,
            ],
            [
                'key' => 'action_ios_explorer',
                'name' => 'iOS Explorer',
                'icon' => '🍎',
                'category' => 'discovery',
                'tier' => 'bronze',
                'description' => 'Install the app on iOS',
                'criteria' => ['action_app_installed_ios' => 1],
                'points' => 10,
            ],
            [
                'key' => 'action_desktop_explorer',
                'name' => 'Desktop Explorer',
                'icon' => '🖥️',
                'category' => 'discovery',
                'tier' => 'bronze',
                'description' => 'Install the app on Desktop',
                'criteria' => ['action_app_installed_desktop' => 1],
                'points' => 10,
            ],
            [
                'key' => 'action_first_download',
                'name' => 'First Download',
                'icon' => '📥',
                'category' => 'milestone',
                'tier' => 'bronze',
                'description' => 'Download your first book',
                'criteria' => ['action_book_downloaded' => 1],
                'points' => 10,
            ],
            [
                'key' => 'action_first_listen',
                'name' => 'First Listen',
                'icon' => '▶️',
                'category' => 'milestone',
                'tier' => 'bronze',
                'description' => 'Start playing your first book',
                'criteria' => ['action_book_started' => 1],
                'points' => 10,
            ],
            [
                'key' => 'action_skin_stylist',
                'name' => 'Skin Stylist',
                'icon' => '🎨',
                'category' => 'exploration',
                'tier' => 'bronze',
                'description' => 'Change the player skin for the first time',
                'criteria' => ['action_skin_changed' => 1],
                'points' => 10,
            ],
            [
                'key' => 'action_gallery_shopper',
                'name' => 'Gallery Shopper',
                'icon' => '🛍️',
                'category' => 'exploration',
                'tier' => 'silver',
                'description' => 'Download your first skin from the gallery',
                'criteria' => ['action_gallery_skin_downloaded' => 1],
                'points' => 25,
            ],
            [
                'key' => 'action_theme_artist',
                'name' => 'Theme Artist',
                'icon' => '🌈',
                'category' => 'exploration',
                'tier' => 'bronze',
                'description' => 'Change the color theme',
                'criteria' => ['action_theme_changed' => 1],
                'points' => 10,
            ],
            [
                'key' => 'action_road_warrior',
                'name' => 'Road Warrior',
                'icon' => '🚗',
                'category' => 'exploration',
                'tier' => 'bronze',
                'description' => 'Turn on drive mode for the first time',
                'criteria' => ['action_drive_mode_activated' => 1],
                'points' => 10,
            ],
            [
                'key' => 'action_bookmarker',
                'name' => 'Bookmarker',
                'icon' => '🔖',
                'category' => 'discovery',
                'tier' => 'bronze',
                'description' => 'Create your first bookmark',
                'criteria' => ['action_bookmark_created' => 1],
                'points' => 10,
            ],
        ];
    }
}
