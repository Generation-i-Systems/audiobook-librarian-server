<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Badge;

class BadgeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $badges = $this->getBadgeDefinitions();

        foreach ($badges as $badgeData) {
            Badge::updateOrCreate(
                ['key' => $badgeData['key']], // Update if exists, create if not
                $badgeData
            );
        }
    }

    /**
     * Get comprehensive badge definitions similar to Audible achievements
     */
    private function getBadgeDefinitions(): array
    {
        return [
            // === LISTENING TIME MILESTONES ===
            [
                'key' => 'first_hour',
                'name' => 'First Hour',
                'description' => 'Listen for your first hour of audio content',
                'icon' => '🎧',
                'category' => 'milestone',
                'tier' => 'bronze',
                'points' => 10,
                'criteria' => [
                    'total_listening_time' => 3600 // 1 hour in seconds
                ],
                'sort_order' => 100,
            ],
            [
                'key' => 'dedicated_listener',
                'name' => 'Dedicated Listener',
                'description' => 'Listen for 10 hours of audio content',
                'icon' => '📚',
                'category' => 'milestone',
                'tier' => 'silver',
                'points' => 25,
                'criteria' => [
                    'total_listening_time' => 36000 // 10 hours
                ],
                'sort_order' => 101,
            ],
            [
                'key' => 'committed_reader',
                'name' => 'Committed Reader',
                'description' => 'Listen for 50 hours of audio content',
                'icon' => '📖',
                'category' => 'milestone',
                'tier' => 'gold',
                'points' => 50,
                'criteria' => [
                    'total_listening_time' => 180000 // 50 hours
                ],
                'sort_order' => 102,
            ],
            [
                'key' => 'audiobook_master',
                'name' => 'Audiobook Master',
                'description' => 'Listen for 100 hours of audio content',
                'icon' => '👑',
                'category' => 'milestone',
                'tier' => 'platinum',
                'points' => 100,
                'criteria' => [
                    'total_listening_time' => 360000 // 100 hours
                ],
                'sort_order' => 103,
            ],
            [
                'key' => 'legendary_listener',
                'name' => 'Legendary Listener',
                'description' => 'Listen for 500 hours of audio content',
                'icon' => '💎',
                'category' => 'milestone',
                'tier' => 'diamond',
                'points' => 250,
                'criteria' => [
                    'total_listening_time' => 1800000 // 500 hours
                ],
                'sort_order' => 104,
            ],

            // === BOOK COMPLETION BADGES ===
            [
                'key' => 'first_book',
                'name' => 'First Book',
                'description' => 'Complete your first audiobook',
                'icon' => '📗',
                'category' => 'completion',
                'tier' => 'bronze',
                'points' => 15,
                'criteria' => [
                    'books_completed' => 1
                ],
                'sort_order' => 200,
            ],
            [
                'key' => 'book_collector',
                'name' => 'Book Collector',
                'description' => 'Complete 5 audiobooks',
                'icon' => '📚',
                'category' => 'completion',
                'tier' => 'silver',
                'points' => 30,
                'criteria' => [
                    'books_completed' => 5
                ],
                'sort_order' => 201,
            ],
            [
                'key' => 'bibliophile',
                'name' => 'Bibliophile',
                'description' => 'Complete 25 audiobooks',
                'icon' => '📖',
                'category' => 'completion',
                'tier' => 'gold',
                'points' => 75,
                'criteria' => [
                    'books_completed' => 25
                ],
                'sort_order' => 202,
            ],
            [
                'key' => 'book_master',
                'name' => 'Book Master',
                'description' => 'Complete 50 audiobooks',
                'icon' => '🏆',
                'category' => 'completion',
                'tier' => 'platinum',
                'points' => 150,
                'criteria' => [
                    'books_completed' => 50
                ],
                'sort_order' => 203,
            ],
            [
                'key' => 'reading_champion',
                'name' => 'Reading Champion',
                'description' => 'Complete 100 audiobooks',
                'icon' => '🥇',
                'category' => 'completion',
                'tier' => 'diamond',
                'points' => 300,
                'criteria' => [
                    'books_completed' => 100
                ],
                'sort_order' => 204,
            ],

            // === STREAK BADGES ===
            [
                'key' => 'getting_started',
                'name' => 'Getting Started',
                'description' => 'Listen for 3 consecutive days',
                'icon' => '🔥',
                'category' => 'streak',
                'tier' => 'bronze',
                'points' => 20,
                'criteria' => [
                    'current_streak' => 3
                ],
                'sort_order' => 300,
            ],
            [
                'key' => 'on_fire',
                'name' => 'On Fire',
                'description' => 'Listen for 7 consecutive days',
                'icon' => '🔥',
                'category' => 'streak',
                'tier' => 'silver',
                'points' => 35,
                'criteria' => [
                    'current_streak' => 7
                ],
                'sort_order' => 301,
            ],
            [
                'key' => 'unstoppable',
                'name' => 'Unstoppable',
                'description' => 'Listen for 30 consecutive days',
                'icon' => '🔥',
                'category' => 'streak',
                'tier' => 'gold',
                'points' => 100,
                'criteria' => [
                    'current_streak' => 30
                ],
                'sort_order' => 302,
            ],
            [
                'key' => 'dedication_master',
                'name' => 'Dedication Master',
                'description' => 'Listen for 100 consecutive days',
                'icon' => '🔥',
                'category' => 'streak',
                'tier' => 'platinum',
                'points' => 250,
                'criteria' => [
                    'current_streak' => 100
                ],
                'sort_order' => 303,
            ],
            [
                'key' => 'streak_legend',
                'name' => 'Streak Legend',
                'description' => 'Listen for 365 consecutive days',
                'icon' => '🔥',
                'category' => 'streak',
                'tier' => 'diamond',
                'points' => 500,
                'criteria' => [
                    'current_streak' => 365
                ],
                'sort_order' => 304,
            ],

            // === VARIETY AND EXPLORATION BADGES ===
            [
                'key' => 'genre_explorer',
                'name' => 'Genre Explorer',
                'description' => 'Listen to books from 3 different genres',
                'icon' => '🗺️',
                'category' => 'variety',
                'tier' => 'bronze',
                'points' => 25,
                'criteria' => [
                    'genres_explored' => 3
                ],
                'sort_order' => 400,
            ],
            [
                'key' => 'diverse_reader',
                'name' => 'Diverse Reader',
                'description' => 'Listen to books from 7 different genres',
                'icon' => '🌈',
                'category' => 'variety',
                'tier' => 'silver',
                'points' => 40,
                'criteria' => [
                    'genres_explored' => 7
                ],
                'sort_order' => 401,
            ],
            [
                'key' => 'omnivorous_reader',
                'name' => 'Omnivorous Reader',
                'description' => 'Listen to books from 15 different genres',
                'icon' => '🎭',
                'category' => 'variety',
                'tier' => 'gold',
                'points' => 80,
                'criteria' => [
                    'genres_explored' => 15
                ],
                'sort_order' => 402,
            ],
            [
                'key' => 'author_explorer',
                'name' => 'Author Explorer',
                'description' => 'Listen to books from 10 different authors',
                'icon' => '✍️',
                'category' => 'variety',
                'tier' => 'silver',
                'points' => 35,
                'criteria' => [
                    'authors_explored' => 10
                ],
                'sort_order' => 403,
            ],
            [
                'key' => 'voice_connoisseur',
                'name' => 'Voice Connoisseur',
                'description' => 'Listen to books narrated by 5 different narrators',
                'icon' => '🎤',
                'category' => 'variety',
                'tier' => 'gold',
                'points' => 60,
                'criteria' => [
                    'narrator_variety' => 5
                ],
                'sort_order' => 404,
            ],

            // === DEDICATION AND SPEED BADGES ===
            [
                'key' => 'marathon_session',
                'name' => 'Marathon Session',
                'description' => 'Listen for 4+ hours in a single session',
                'icon' => '🏃‍♂️',
                'category' => 'dedication',
                'tier' => 'gold',
                'points' => 50,
                'criteria' => [
                    'longest_session' => 14400 // 4 hours
                ],
                'sort_order' => 500,
            ],
            [
                'key' => 'epic_session',
                'name' => 'Epic Session',
                'description' => 'Listen for 8+ hours in a single session',
                'icon' => '🏆',
                'category' => 'dedication',
                'tier' => 'platinum',
                'points' => 100,
                'criteria' => [
                    'longest_session' => 28800 // 8 hours
                ],
                'sort_order' => 501,
            ],
            [
                'key' => 'weekend_warrior',
                'name' => 'Weekend Warrior',
                'description' => 'Complete 10 listening sessions on weekends',
                'icon' => '🏖️',
                'category' => 'dedication',
                'tier' => 'silver',
                'points' => 40,
                'criteria' => [
                    'weekend_listening' => 10
                ],
                'sort_order' => 502,
            ],
            [
                'key' => 'speed_reader',
                'name' => 'Speed Reader',
                'description' => 'Complete 3 books in one month',
                'icon' => '⚡',
                'category' => 'speed',
                'tier' => 'gold',
                'points' => 75,
                'criteria' => [
                    'books_completed_this_month' => 3
                ],
                'sort_order' => 503,
            ],
            [
                'key' => 'lightning_reader',
                'name' => 'Lightning Reader',
                'description' => 'Complete 2 books in one week',
                'icon' => '⚡',
                'category' => 'speed',
                'tier' => 'platinum',
                'points' => 100,
                'criteria' => [
                    'books_completed_this_week' => 2
                ],
                'sort_order' => 504,
            ],

            // === SESSION AND ACTIVITY BADGES ===
            [
                'key' => 'active_listener',
                'name' => 'Active Listener',
                'description' => 'Complete 25 listening sessions',
                'icon' => '🎧',
                'category' => 'listening',
                'tier' => 'bronze',
                'points' => 20,
                'criteria' => [
                    'session_count' => 25
                ],
                'sort_order' => 600,
            ],
            [
                'key' => 'frequent_listener',
                'name' => 'Frequent Listener',
                'description' => 'Complete 100 listening sessions',
                'icon' => '🎵',
                'category' => 'listening',
                'tier' => 'silver',
                'points' => 45,
                'criteria' => [
                    'session_count' => 100
                ],
                'sort_order' => 601,
            ],
            [
                'key' => 'session_master',
                'name' => 'Session Master',
                'description' => 'Complete 500 listening sessions',
                'icon' => '🎼',
                'category' => 'listening',
                'tier' => 'gold',
                'points' => 120,
                'criteria' => [
                    'session_count' => 500
                ],
                'sort_order' => 602,
            ],

            // === LONGEVITY BADGES ===
            [
                'key' => 'one_month_member',
                'name' => 'One Month Member',
                'description' => 'Listen for 30 different days',
                'icon' => '📅',
                'category' => 'dedication',
                'tier' => 'bronze',
                'points' => 30,
                'criteria' => [
                    'total_listening_days' => 30
                ],
                'sort_order' => 700,
            ],
            [
                'key' => 'seasoned_listener',
                'name' => 'Seasoned Listener',
                'description' => 'Listen for 100 different days',
                'icon' => '🗓️',
                'category' => 'dedication',
                'tier' => 'gold',
                'points' => 80,
                'criteria' => [
                    'total_listening_days' => 100
                ],
                'sort_order' => 701,
            ],
            [
                'key' => 'year_long_listener',
                'name' => 'Year-Long Listener',
                'description' => 'Listen for 365 different days',
                'icon' => '🎂',
                'category' => 'dedication',
                'tier' => 'diamond',
                'points' => 365,
                'criteria' => [
                    'total_listening_days' => 365
                ],
                'sort_order' => 702,
            ],

            // === SPECIAL ACHIEVEMENT BADGES ===
            [
                'key' => 'early_bird',
                'name' => 'Early Bird',
                'description' => 'Complete 5 morning listening sessions (before 8 AM)',
                'icon' => '🌅',
                'category' => 'exploration',
                'tier' => 'silver',
                'points' => 35,
                'criteria' => [
                    'time_of_day_listening' => 5
                ],
                'sort_order' => 800,
            ],
            [
                'key' => 'night_owl',
                'name' => 'Night Owl',
                'description' => 'Complete 5 late night listening sessions (after 10 PM)',
                'icon' => '🦉',
                'category' => 'exploration',
                'tier' => 'silver',
                'points' => 35,
                'criteria' => [
                    'time_of_day_listening' => 5
                ],
                'sort_order' => 801,
            ],

            // === REPEATABLE MILESTONE BADGES ===
            [
                'key' => 'weekly_goal_met',
                'name' => 'Weekly Goal',
                'description' => 'Listen for 7+ hours in a single week',
                'icon' => '🎯',
                'category' => 'milestone',
                'tier' => 'bronze',
                'points' => 15,
                'criteria' => [
                    'listening_time_weekly' => 25200 // 7 hours
                ],
                'is_repeatable' => true,
                'sort_order' => 900,
            ],
            [
                'key' => 'monthly_champion',
                'name' => 'Monthly Champion',
                'description' => 'Listen for 30+ hours in a single month',
                'icon' => '🏅',
                'category' => 'milestone',
                'tier' => 'gold',
                'points' => 50,
                'criteria' => [
                    'listening_time_monthly' => 108000 // 30 hours
                ],
                'is_repeatable' => true,
                'sort_order' => 901,
            ],
        ];
    }
}
