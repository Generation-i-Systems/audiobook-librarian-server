<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Badge;

class ExpandedBadgeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $badges = $this->getExpandedBadgeDefinitions();

        foreach ($badges as $badgeData) {
            Badge::updateOrCreate(
                ['key' => $badgeData['key']], // Update if exists, create if not
                $badgeData
            );
        }
    }

    /**
     * Get expanded badge definitions with new categories
     */
    private function getExpandedBadgeDefinitions(): array
    {
        return [
            // === DISCOVERY & EXPLORATION BADGES ===
            [
                'key' => 'hidden_gem_finder',
                'name' => 'Hidden Gem Finder',
                'description' => 'Discover 5 books with fewer than 100 listeners',
                'icon' => '💎',
                'category' => 'discovery',
                'tier' => 'silver',
                'points' => 40,
                'criteria' => [
                    'indie_discovery' => 5
                ],
                'sort_order' => 1100,
            ],
            [
                'key' => 'trend_setter',
                'name' => 'Trend Setter',
                'description' => 'Be among the first 10 listeners of 3 newly added books',
                'icon' => '🔥',
                'category' => 'discovery',
                'tier' => 'gold',
                'points' => 75,
                'criteria' => [
                    'discovery_rate' => 3
                ],
                'sort_order' => 1101,
            ],
            [
                'key' => 'archive_explorer',
                'name' => 'Archive Explorer',
                'description' => 'Listen to 10 books published before 1950',
                'icon' => '📜',
                'category' => 'discovery',
                'tier' => 'gold',
                'points' => 60,
                'criteria' => [
                    'publication_era' => 10
                ],
                'sort_order' => 1102,
            ],
            [
                'key' => 'debut_supporter',
                'name' => 'Debut Supporter',
                'description' => 'Complete 5 books by first-time authors',
                'icon' => '🌱',
                'category' => 'discovery',
                'tier' => 'silver',
                'points' => 35,
                'criteria' => [
                    'first_time_author' => 5
                ],
                'sort_order' => 1103,
            ],

            // === QUALITY & MASTERY BADGES ===
            [
                'key' => 'focused_listener',
                'name' => 'Focused Listener',
                'description' => 'Complete 10 sessions with fewer than 3 pauses each',
                'icon' => '🎯',
                'category' => 'quality',
                'tier' => 'gold',
                'points' => 50,
                'criteria' => [
                    'pause_behavior' => 10
                ],
                'sort_order' => 1200,
            ],
            [
                'key' => 'completion_perfectionist',
                'name' => 'Completion Perfectionist',
                'description' => 'Maintain a 90% book completion rate over 20 books',
                'icon' => '✨',
                'category' => 'quality',
                'tier' => 'platinum',
                'points' => 100,
                'criteria' => [
                    'completion_rate' => 90,
                    'books_completed' => 20
                ],
                'sort_order' => 1201,
            ],
            [
                'key' => 'speed_reader',
                'name' => 'Speed Reader',
                'description' => 'Maintain an average listening speed of 1.5x or higher',
                'icon' => '🚀',
                'category' => 'mastery',
                'tier' => 'silver',
                'points' => 40,
                'criteria' => [
                    'reading_speed' => 150 // 1.5x speed
                ],
                'sort_order' => 1202,
            ],
            [
                'key' => 'chapter_champion',
                'name' => 'Chapter Champion',
                'description' => 'Complete 500 individual chapters',
                'icon' => '📑',
                'category' => 'mastery',
                'tier' => 'gold',
                'points' => 75,
                'criteria' => [
                    'chapter_completion' => 500
                ],
                'sort_order' => 1203,
            ],

            // === COLLECTION & LIBRARY BADGES ===
            [
                'key' => 'library_builder',
                'name' => 'Library Builder',
                'description' => 'Build a personal library of 50 books',
                'icon' => '📚',
                'category' => 'collection',
                'tier' => 'silver',
                'points' => 45,
                'criteria' => [
                    'library_size' => 50
                ],
                'sort_order' => 1300,
            ],
            [
                'key' => 'massive_collection',
                'name' => 'Massive Collection',
                'description' => 'Build a personal library of 200 books',
                'icon' => '🏛️',
                'category' => 'collection',
                'tier' => 'platinum',
                'points' => 120,
                'criteria' => [
                    'library_size' => 200
                ],
                'sort_order' => 1301,
            ],
            [
                'key' => 'bookmark_master',
                'name' => 'Bookmark Master',
                'description' => 'Create 100 bookmarks across different books',
                'icon' => '🔖',
                'category' => 'collection',
                'tier' => 'gold',
                'points' => 55,
                'criteria' => [
                    'bookmarks_created' => 100
                ],
                'sort_order' => 1302,
            ],
            [
                'key' => 'series_collector',
                'name' => 'Series Collector',
                'description' => 'Complete 5 entire book series',
                'icon' => '📖',
                'category' => 'collection',
                'tier' => 'diamond',
                'points' => 150,
                'criteria' => [
                    'series_completion' => 5
                ],
                'sort_order' => 1303,
            ],

            // === SEASONAL & TIME-BASED BADGES ===
            [
                'key' => 'spring_reader',
                'name' => 'Spring Reader',
                'description' => 'Listen for 20 hours during spring months (Mar-May)',
                'icon' => '🌸',
                'category' => 'seasonal',
                'tier' => 'bronze',
                'points' => 25,
                'criteria' => [
                    'seasonal_listening' => 72000 // 20 hours in seconds
                ],
                'is_repeatable' => true,
                'sort_order' => 1400,
            ],
            [
                'key' => 'summer_listener',
                'name' => 'Summer Listener',
                'description' => 'Listen for 30 hours during summer months (Jun-Aug)',
                'icon' => '☀️',
                'category' => 'seasonal',
                'tier' => 'silver',
                'points' => 35,
                'criteria' => [
                    'seasonal_listening' => 108000 // 30 hours
                ],
                'is_repeatable' => true,
                'sort_order' => 1401,
            ],
            [
                'key' => 'autumn_enthusiast',
                'name' => 'Autumn Enthusiast',
                'description' => 'Listen for 25 hours during autumn months (Sep-Nov)',
                'icon' => '🍂',
                'category' => 'seasonal',
                'tier' => 'bronze',
                'points' => 30,
                'criteria' => [
                    'seasonal_listening' => 90000 // 25 hours
                ],
                'is_repeatable' => true,
                'sort_order' => 1402,
            ],
            [
                'key' => 'winter_warrior',
                'name' => 'Winter Warrior',
                'description' => 'Listen for 40 hours during winter months (Dec-Feb)',
                'icon' => '❄️',
                'category' => 'seasonal',
                'tier' => 'gold',
                'points' => 50,
                'criteria' => [
                    'seasonal_listening' => 144000 // 40 hours
                ],
                'is_repeatable' => true,
                'sort_order' => 1403,
            ],
            [
                'key' => 'midnight_reader',
                'name' => 'Midnight Reader',
                'description' => 'Complete 10 listening sessions after midnight',
                'icon' => '🌙',
                'category' => 'time_based',
                'tier' => 'silver',
                'points' => 35,
                'criteria' => [
                    'time_of_day_listening' => 10
                ],
                'sort_order' => 1404,
            ],
            [
                'key' => 'dawn_listener',
                'name' => 'Dawn Listener',
                'description' => 'Complete 15 listening sessions between 5-7 AM',
                'icon' => '🌅',
                'category' => 'time_based',
                'tier' => 'gold',
                'points' => 45,
                'criteria' => [
                    'time_of_day_listening' => 15
                ],
                'sort_order' => 1405,
            ],

            // === CHALLENGE BADGES ===
            [
                'key' => 'marathon_month',
                'name' => 'Marathon Month',
                'description' => 'Listen for 100+ hours in a single month',
                'icon' => '🏃‍♂️',
                'category' => 'challenge',
                'tier' => 'diamond',
                'points' => 200,
                'criteria' => [
                    'listening_time_monthly' => 360000 // 100 hours
                ],
                'is_repeatable' => true,
                'sort_order' => 1500,
            ],
            [
                'key' => 'genre_master',
                'name' => 'Genre Master',
                'description' => 'Complete 5 books in 10 different genres',
                'icon' => '🎭',
                'category' => 'challenge',
                'tier' => 'platinum',
                'points' => 150,
                'criteria' => [
                    'genres_explored' => 10,
                    'books_completed' => 50
                ],
                'sort_order' => 1501,
            ],
            [
                'key' => 'polyglot_reader',
                'name' => 'Polyglot Reader',
                'description' => 'Listen to books in 3 different languages',
                'icon' => '🌍',
                'category' => 'challenge',
                'tier' => 'gold',
                'points' => 80,
                'criteria' => [
                    'language_variety' => 3
                ],
                'sort_order' => 1502,
            ],
            [
                'key' => 'device_nomad',
                'name' => 'Device Nomad',
                'description' => 'Listen on 5 different devices',
                'icon' => '📱',
                'category' => 'challenge',
                'tier' => 'silver',
                'points' => 30,
                'criteria' => [
                    'device_variety' => 5
                ],
                'sort_order' => 1503,
            ],
            [
                'key' => 'offline_adventurer',
                'name' => 'Offline Adventurer',
                'description' => 'Listen for 20 hours while offline',
                'icon' => '✈️',
                'category' => 'challenge',
                'tier' => 'gold',
                'points' => 60,
                'criteria' => [
                    'offline_listening' => 72000 // 20 hours
                ],
                'sort_order' => 1504,
            ],

            // === HABIT BUILDING BADGES ===
            [
                'key' => 'morning_routine',
                'name' => 'Morning Routine',
                'description' => 'Listen every morning (6-10 AM) for 14 consecutive days',
                'icon' => '☕',
                'category' => 'habit',
                'tier' => 'gold',
                'points' => 60,
                'criteria' => [
                    'time_of_day_listening' => 14,
                    'listening_streak' => 14
                ],
                'sort_order' => 1600,
            ],
            [
                'key' => 'commute_companion',
                'name' => 'Commute Companion',
                'description' => 'Complete 30 listening sessions during peak commute hours',
                'icon' => '🚗',
                'category' => 'habit',
                'tier' => 'silver',
                'points' => 40,
                'criteria' => [
                    'time_of_day_listening' => 30
                ],
                'sort_order' => 1601,
            ],
            [
                'key' => 'bedtime_stories',
                'name' => 'Bedtime Stories',
                'description' => 'Listen every evening (8-11 PM) for 21 consecutive days',
                'icon' => '🌙',
                'category' => 'habit',
                'tier' => 'gold',
                'points' => 65,
                'criteria' => [
                    'time_of_day_listening' => 21,
                    'listening_streak' => 21
                ],
                'sort_order' => 1602,
            ],
            [
                'key' => 'lunch_break_learner',
                'name' => 'Lunch Break Learner',
                'description' => 'Complete 20 lunch-time sessions (11 AM - 2 PM)',
                'icon' => '🥪',
                'category' => 'habit',
                'tier' => 'bronze',
                'points' => 25,
                'criteria' => [
                    'time_of_day_listening' => 20
                ],
                'sort_order' => 1603,
            ],

            // === SPECIAL EVENT BADGES ===
            [
                'key' => 'new_year_resolution',
                'name' => 'New Year Resolution',
                'description' => 'Complete 10 books in January',
                'icon' => '🎊',
                'category' => 'special',
                'tier' => 'gold',
                'points' => 100,
                'criteria' => [
                    'books_in_timeframe' => 10
                ],
                'is_repeatable' => true,
                'sort_order' => 1700,
            ],
            [
                'key' => 'valentines_romantic',
                'name' => 'Valentine\'s Romantic',
                'description' => 'Complete 3 romance books in February',
                'icon' => '💕',
                'category' => 'special',
                'tier' => 'silver',
                'points' => 35,
                'criteria' => [
                    'books_in_timeframe' => 3
                ],
                'is_repeatable' => true,
                'sort_order' => 1701,
            ],
            [
                'key' => 'halloween_horror',
                'name' => 'Halloween Horror',
                'description' => 'Complete 5 horror/thriller books in October',
                'icon' => '🎃',
                'category' => 'special',
                'tier' => 'gold',
                'points' => 50,
                'criteria' => [
                    'books_in_timeframe' => 5
                ],
                'is_repeatable' => true,
                'sort_order' => 1702,
            ],
            [
                'key' => 'summer_reading',
                'name' => 'Summer Reading Challenge',
                'description' => 'Complete 12 books during summer break (Jun-Aug)',
                'icon' => '🏖️',
                'category' => 'special',
                'tier' => 'platinum',
                'points' => 120,
                'criteria' => [
                    'books_in_timeframe' => 12
                ],
                'is_repeatable' => true,
                'sort_order' => 1703,
            ],

            // === COMMUNITY & SOCIAL BADGES ===
            [
                'key' => 'reviewer_extraordinaire',
                'name' => 'Reviewer Extraordinaire',
                'description' => 'Write detailed reviews for 25 books',
                'icon' => '✍️',
                'category' => 'community',
                'tier' => 'gold',
                'points' => 75,
                'criteria' => [
                    'books_reviewed' => 25
                ],
                'sort_order' => 1800,
            ],
            [
                'key' => 'helpful_recommender',
                'name' => 'Helpful Recommender',
                'description' => 'Have 10 book recommendations followed by others',
                'icon' => '👥',
                'category' => 'community',
                'tier' => 'platinum',
                'points' => 100,
                'criteria' => [
                    'community_engagement' => 10
                ],
                'sort_order' => 1801,
            ],
            [
                'key' => 'discussion_starter',
                'name' => 'Discussion Starter',
                'description' => 'Start 20 book discussions in the community',
                'icon' => '💬',
                'category' => 'community',
                'tier' => 'silver',
                'points' => 45,
                'criteria' => [
                    'community_engagement' => 20
                ],
                'sort_order' => 1802,
            ],
            [
                'key' => 'mentor',
                'name' => 'Reading Mentor',
                'description' => 'Help 5 new users discover their first books',
                'icon' => '🎓',
                'category' => 'community',
                'tier' => 'diamond',
                'points' => 200,
                'criteria' => [
                    'community_engagement' => 5
                ],
                'sort_order' => 1803,
            ],

            // === MASTERY & EXPERTISE BADGES ===
            [
                'key' => 'repeat_listener',
                'name' => 'Repeat Listener',
                'description' => 'Listen to 10 books more than once',
                'icon' => '🔄',
                'category' => 'mastery',
                'tier' => 'silver',
                'points' => 40,
                'criteria' => [
                    'repeat_listening' => 10
                ],
                'sort_order' => 1900,
            ],
            [
                'key' => 'length_specialist_short',
                'name' => 'Short Story Specialist',
                'description' => 'Complete 20 books under 3 hours',
                'icon' => '📝',
                'category' => 'mastery',
                'tier' => 'bronze',
                'points' => 30,
                'criteria' => [
                    'length_preference' => 20
                ],
                'sort_order' => 1901,
            ],
            [
                'key' => 'length_specialist_epic',
                'name' => 'Epic Listener',
                'description' => 'Complete 10 books over 20 hours each',
                'icon' => '📚',
                'category' => 'mastery',
                'tier' => 'platinum',
                'points' => 120,
                'criteria' => [
                    'length_preference' => 10
                ],
                'sort_order' => 1902,
            ],
            [
                'key' => 'award_winner_hunter',
                'name' => 'Award Winner Hunter',
                'description' => 'Complete 15 award-winning books',
                'icon' => '🏆',
                'category' => 'mastery',
                'tier' => 'gold',
                'points' => 80,
                'criteria' => [
                    'award_winners' => 15
                ],
                'sort_order' => 1903,
            ],
            [
                'key' => 'bestseller_reader',
                'name' => 'Bestseller Reader',
                'description' => 'Complete 25 bestseller books',
                'icon' => '📈',
                'category' => 'mastery',
                'tier' => 'gold',
                'points' => 70,
                'criteria' => [
                    'bestseller_reading' => 25
                ],
                'sort_order' => 1904,
            ],

            // === UNIQUE ACHIEVEMENT BADGES ===
            [
                'key' => 'time_traveler',
                'name' => 'Time Traveler',
                'description' => 'Listen to books spanning 5 different decades',
                'icon' => '⏳',
                'category' => 'discovery',
                'tier' => 'diamond',
                'points' => 150,
                'criteria' => [
                    'publication_era' => 5
                ],
                'sort_order' => 2000,
            ],
            [
                'key' => 'completionist',
                'name' => 'The Completionist',
                'description' => 'Achieve 100% completion rate on 50 books',
                'icon' => '💯',
                'category' => 'mastery',
                'tier' => 'diamond',
                'points' => 300,
                'criteria' => [
                    'completion_rate' => 100,
                    'books_completed' => 50
                ],
                'sort_order' => 2001,
            ],
            [
                'key' => 'omnivorous_mind',
                'name' => 'Omnivorous Mind',
                'description' => 'Complete books in all available genres',
                'icon' => '🧠',
                'category' => 'challenge',
                'tier' => 'diamond',
                'points' => 250,
                'criteria' => [
                    'genres_explored' => 25 // Assuming 25+ genres available
                ],
                'sort_order' => 2002,
            ],
            [
                'key' => 'platinum_listener',
                'name' => 'Platinum Listener',
                'description' => 'Earn 1000 badge points total',
                'icon' => '🌟',
                'category' => 'mastery',
                'tier' => 'diamond',
                'points' => 500,
                'criteria' => [
                    'total_listening_time' => 3600000 // 1000 hours as proxy
                ],
                'sort_order' => 2003,
            ],
        ];
    }
}
