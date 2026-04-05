<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class CanonicalBadgeSeeder extends Seeder
{
    public function run(): void
    {
        Badge::query()->update(['is_active' => false]);

        $order = 1;

        foreach (self::badges() as $badge) {
            $data = array_merge([
                'description' => '',
                'icon' => $this->emojiForCategory($badge['category']),
                'image_url' => '/images/badges/' . $badge['key'] . '.svg',
                'points' => 0,
                'criteria' => [],
                'is_active' => true,
                'is_repeatable' => false,
                'sort_order' => $order++,
            ], $badge);

            Badge::updateOrCreate(['key' => $data['key']], $data);
        }
    }

    public static function badges(): array
    {
        return array_merge(
            self::category('listening', [
                ['listening_starter_bronze', 'Listening Starter', 'bronze', 'Listen for 1 hour', ['total_listening_time' => 3600]],
                ['listening_weekend_listener_silver', 'Weekend Listener', 'silver', 'Listen for 5 hours on weekends', ['weekend_listening_time' => 18000]],
                ['listening_daily_listener_gold', 'Daily Listener', 'gold', 'Listen every day for a week', ['current_streak' => 7]],
                ['listening_100_hours_platinum', '100 Hours', 'platinum', 'Listen for 100 hours total', ['total_listening_time' => 360000]],
                ['listening_250_hours_diamond', '250 Hours', 'diamond', 'Listen for 250 hours total', ['total_listening_time' => 900000]],
                ['listening_500_hours_mythic', '500 Hours', 'diamond', 'Listen for 500 hours total', ['total_listening_time' => 1800000]],
            ]),
            self::category('milestone', [
                ['milestone_first_book_bronze', 'First Book', 'bronze', 'Finish your first book', ['books_completed' => 1]],
                ['milestone_five_books_silver', 'Five Books', 'silver', 'Finish 5 books', ['books_completed' => 5]],
                ['milestone_ten_books_gold', 'Ten Books', 'gold', 'Finish 10 books', ['books_completed' => 10]],
                ['milestone_twentyfive_books_platinum', 'Twenty-Five Books', 'platinum', 'Finish 25 books', ['books_completed' => 25]],
                ['milestone_fifty_books_diamond', 'Fifty Books', 'diamond', 'Finish 50 books', ['books_completed' => 50]],
                ['milestone_one_hundred_books_mythic', 'One Hundred Books', 'diamond', 'Finish 100 books', ['books_completed' => 100]],
            ]),
            self::category('streak', [
                ['streak_3_day_bronze', '3-Day Streak', 'bronze', 'Listen for 3 consecutive days', ['current_streak' => 3]],
                ['streak_7_day_silver', '7-Day Streak', 'silver', 'Listen for 7 consecutive days', ['current_streak' => 7]],
                ['streak_14_day_gold', '14-Day Streak', 'gold', 'Listen for 14 consecutive days', ['current_streak' => 14]],
                ['streak_30_day_platinum', '30-Day Streak', 'platinum', 'Listen for 30 consecutive days', ['current_streak' => 30]],
                ['streak_60_day_diamond', '60-Day Streak', 'diamond', 'Listen for 60 consecutive days', ['current_streak' => 60]],
                ['streak_100_day_mythic', '100-Day Streak', 'diamond', 'Listen for 100 consecutive days', ['current_streak' => 100]],
            ]),
            self::category('variety', [
                ['variety_3_genres_bronze', '3 Genres Explorer', 'bronze', 'Listen to books from 3 genres', ['genres_explored' => 3]],
                ['variety_5_genres_silver', '5 Genres Explorer', 'silver', 'Listen to books from 5 genres', ['genres_explored' => 5]],
                ['variety_8_genres_gold', '8 Genres Explorer', 'gold', 'Listen to books from 8 genres', ['genres_explored' => 8]],
                ['variety_12_genres_platinum', '12 Genres Explorer', 'platinum', 'Listen to books from 12 genres', ['genres_explored' => 12]],
                ['variety_multilingual_diamond', 'Multilingual Listener', 'diamond', 'Listen to books in 3 languages', ['language_variety' => 3]],
                ['variety_new_author_mythic', 'New Author Adventurer', 'diamond', 'Listen to 20 different authors', ['authors_explored' => 20]],
            ]),
            self::category('social', [
                ['social_first_share_bronze', 'First Recommendation', 'bronze', 'Send your first book recommendation', ['recommendations_sent' => 1]],
                ['social_three_shares_silver', 'Three Recommendations', 'silver', 'Send 3 book recommendations', ['recommendations_sent' => 3]],
                ['social_five_reviews_gold', 'Five Reviews', 'gold', 'Review 5 different books', ['books_reviewed' => 5]],
                ['social_ten_reviews_platinum', 'Ten Reviews', 'platinum', 'Review 10 different books', ['books_reviewed' => 10]],
                ['social_helpful_reviews_diamond', 'Helpful Reviewer', 'diamond', 'Review 20 different books', ['books_reviewed' => 20]],
                ['social_community_star_mythic', 'Community Star', 'diamond', 'Send 10 recommendations and review 10 books', ['recommendations_sent' => 10, 'books_reviewed' => 10]],
            ]),
            self::category('completion', [
                ['completion_first_finish_bronze', 'First Finish', 'bronze', 'Finish a book', ['books_completed' => 1]],
                ['completion_week_finish_silver', 'Finish in a Week', 'silver', 'Finish a book within 7 days of starting it', ['quick_finishes' => 1]],
                ['completion_binge_weekend_gold', 'Weekend Binge', 'gold', 'Finish a book on a weekend', ['books_completed_on_weekend' => 1]],
                ['completion_no_abandons_platinum', 'Finish What You Start', 'platinum', 'Keep a 100% completion rate across 10 books', ['completion_rate' => 100, 'books_completed' => 10]],
                ['completion_5_in_row_diamond', 'Five in a Row', 'diamond', 'Finish 5 books within a week of starting them', ['quick_finishes' => 5]],
                ['completion_12_in_month_mythic', 'Dozen in a Month', 'diamond', 'Finish 12 books in one month', ['books_completed_this_month' => 12]],
            ]),
            self::category('speed', [
                ['speed_fast_start_bronze', 'Fast Start', 'bronze', 'Listen at faster than normal speed for 30 minutes', ['speed_time_110' => 1800]],
                ['speed_1_25x_silver', '1.25x Speedster', 'silver', 'Listen at 1.25x+ for 5 hours', ['speed_time_125' => 18000]],
                ['speed_1_5x_gold', '1.5x Speedster', 'gold', 'Listen at 1.5x+ for 10 hours', ['speed_time_150' => 36000]],
                ['speed_1_75x_platinum', '1.75x Speedster', 'platinum', 'Listen at 1.75x+ for 20 hours', ['speed_time_175' => 72000]],
                ['speed_2x_diamond', '2x Speed Elite', 'diamond', 'Listen at 2x+ for 50 hours', ['speed_time_200' => 180000]],
                ['speed_variable_master_mythic', 'Variable Speed Master', 'diamond', 'Spend meaningful time at 3 playback speeds', ['speed_variety' => 3]],
            ]),
            self::category('exploration', [
                ['exploration_new_series_bronze', 'New Series Sampler', 'bronze', 'Finish a book in your first series', ['series_explored' => 1]],
                ['exploration_first_series_finish_silver', 'First Series Finish', 'silver', 'Complete a full series', ['series_completion' => 1]],
                ['exploration_three_series_gold', 'Three Series Explorer', 'gold', 'Finish a book in 3 different series', ['series_explored' => 3]],
                ['exploration_five_series_platinum', 'Five Series Explorer', 'platinum', 'Finish a book in 5 different series', ['series_explored' => 5]],
                ['exploration_classics_diamond', 'Classics Explorer', 'diamond', 'Listen to 5 classic books', ['classic_books_explored' => 5]],
                ['exploration_indie_gems_mythic', 'Indie Gems', 'diamond', 'Listen to 10 indie books', ['indie_books_explored' => 10]],
            ]),
            self::category('dedication', [
                ['dedication_morning_routine_bronze', 'Morning Routine', 'bronze', 'Listen in the morning 5 times', ['morning_sessions' => 5]],
                ['dedication_evening_routine_silver', 'Evening Routine', 'silver', 'Listen in the evening 10 times', ['evening_sessions' => 10]],
                ['dedication_commute_gold', 'Commute Companion', 'gold', 'Listen during commute hours 20 times', ['commute_sessions' => 20]],
                ['dedication_weekly_goal_platinum', 'Weekly Goal Keeper', 'platinum', 'Hit a weekly listening goal 4 weeks in a row', ['weekly_goal_streak' => 4]],
                ['dedication_monthly_goal_diamond', 'Monthly Goal Crusher', 'diamond', 'Hit a monthly listening goal 3 months in a row', ['monthly_goal_streak' => 3]],
                ['dedication_yearly_goal_mythic', 'Yearly Goal Achiever', 'diamond', 'Reach an active yearly listening goal', ['yearly_goal_achieved' => 1]],
            ]),
            self::category('discovery', [
                ['discovery_first_wishlist_bronze', 'First Bookmark', 'bronze', 'Create your first bookmark', ['bookmarks_created' => 1]],
                ['discovery_five_wishlist_silver', 'Five Bookmarks', 'silver', 'Create 5 bookmarks', ['bookmarks_created' => 5]],
                ['discovery_curator_gold', 'Playlist Creator', 'gold', 'Create your first playlist', ['playlist_count' => 1]],
                ['discovery_sampler_platinum', 'Sampler', 'platinum', 'Start 10 different books', ['books_started' => 10]],
                ['discovery_recommendation_diamond', 'Recommendation Pro', 'diamond', 'Read 5 recommended books', ['discovery_rate' => 5]],
                ['discovery_trailblazer_mythic', 'Trailblazer', 'diamond', 'Try your first indie title', ['indie_books_explored' => 1]],
            ]),
            self::category('seasonal', [
                ['seasonal_new_year_bronze', 'New Year Kickoff', 'bronze', 'Listen on New Year\'s Day', ['new_year_sessions' => 1]],
                ['seasonal_spring_refresh_silver', 'Spring Refresh', 'silver', 'Listen on 5 different spring days', ['spring_sessions' => 5]],
                ['seasonal_summer_reading_gold', 'Summer Reading', 'gold', 'Listen on 10 different summer days', ['summer_sessions' => 10]],
                ['seasonal_autumn_stacks_platinum', 'Autumn Stacks', 'platinum', 'Listen on 10 different autumn days', ['autumn_sessions' => 10]],
                ['seasonal_winter_warmers_diamond', 'Winter Warmers', 'diamond', 'Listen on 10 different winter days', ['winter_sessions' => 10]],
                ['seasonal_anniversary_mythic', 'Listening Anniversary', 'diamond', 'Listen on your listening anniversary', ['anniversary_sessions' => 1]],
            ]),
            self::category('collection', [
                ['collection_first_library_bronze', 'First Library', 'bronze', 'Have 1 book in your library', ['library_size' => 1]],
                ['collection_25_library_silver', '25 in Library', 'silver', 'Have 25 books in your library', ['library_size' => 25]],
                ['collection_50_library_gold', '50 in Library', 'gold', 'Have 50 books in your library', ['library_size' => 50]],
                ['collection_100_library_platinum', '100 in Library', 'platinum', 'Have 100 books in your library', ['library_size' => 100]],
                ['collection_series_complete_diamond', 'Series Complete', 'diamond', 'Complete a full series in your library', ['series_completion' => 1]],
                ['collection_curated_sets_mythic', 'Curated Sets', 'diamond', 'Create 5 playlists', ['playlist_count' => 5]],
            ]),
            self::category('habit', [
                ['habit_first_week_bronze', 'Habit Starter', 'bronze', 'Listen for 1 week straight', ['current_streak' => 7]],
                ['habit_two_weeks_silver', 'Two Weeks Habit', 'silver', 'Listen for 2 weeks straight', ['current_streak' => 14]],
                ['habit_month_streak_gold', 'Month Habit', 'gold', 'Listen for 1 month straight', ['current_streak' => 30]],
                ['habit_three_months_platinum', 'Three Months Habit', 'platinum', 'Listen for 3 months straight', ['current_streak' => 90]],
                ['habit_six_months_diamond', 'Six Months Habit', 'diamond', 'Listen for 6 months straight', ['current_streak' => 180]],
                ['habit_year_habit_mythic', 'Year Habit', 'diamond', 'Listen for 1 year straight', ['current_streak' => 365]],
            ]),
        );
    }

    private static function category(string $category, array $items): array
    {
        return array_map(static function (array $item) use ($category): array {
            $points = [
                'bronze' => 10,
                'silver' => 25,
                'gold' => 50,
                'platinum' => 100,
                'diamond' => 250,
            ];

            return [
                'key' => $item[0],
                'name' => $item[1],
                'category' => $category,
                'tier' => $item[2],
                'description' => $item[3],
                'criteria' => $item[4],
                'points' => $points[$item[2]] ?? 0,
            ];
        }, $items);
    }

    private function emojiForCategory(string $category): string
    {
        return [
            'listening' => '🎧',
            'milestone' => '🏁',
            'streak' => '🔥',
            'variety' => '🎲',
            'social' => '💬',
            'completion' => '✅',
            'speed' => '⚡',
            'exploration' => '🧭',
            'dedication' => '📅',
            'discovery' => '🔎',
            'seasonal' => '🍂',
            'collection' => '📚',
            'habit' => '📈',
        ][$category] ?? '🏆';
    }
}
