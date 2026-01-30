<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class CanonicalBadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = $this->badges();
        $order = 1;
        foreach ($badges as $b) {
            $emoji = $this->emojiForCategory($b['category']);
            $data = array_merge([
                'description' => '',
                'icon' => $emoji, // store emoji directly in 'icon'
                'image_url' => '/images/badges/' . $b['key'] . '.svg', // URI to exported SVG
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
        // 13 categories x 6 badges = 78
        return array_merge(
            $this->category('listening', [
                ['listening_starter_bronze', 'Listening Starter', 'bronze', 'Listen for 1 hour', ['total_listening_time' => 3600]],
                ['listening_weekend_listener_silver', 'Weekend Listener', 'silver', 'Listen for 5 hours on a weekend', ['weekend_listening' => 5]],
                ['listening_daily_listener_gold', 'Daily Listener', 'gold', 'Listen everyday for a week', ['listening_streak' => 7]],
                ['listening_100_hours_platinum', '100 Hours', 'platinum', 'Listen for 100 hours total', ['total_listening_time' => 360000]],
                ['listening_250_hours_diamond', '250 Hours', 'diamond', 'Listen for 250 hours total', ['total_listening_time' => 900000]],
                ['listening_500_hours_mythic', '500 Hours', 'diamond', 'Listen for 500 hours total', ['total_listening_time' => 1800000]],
            ]),
            $this->category('milestone', [
                ['milestone_first_book_bronze', 'First Book', 'bronze', 'Finish your first book', ['books_completed' => 1]],
                ['milestone_five_books_silver', 'Five Books', 'silver', 'Finish 5 books', ['books_completed' => 5]],
                ['milestone_ten_books_gold', 'Ten Books', 'gold', 'Finish 10 books', ['books_completed' => 10]],
                ['milestone_twentyfive_books_platinum', 'Twenty-Five Books', 'platinum', 'Finish 25 books', ['books_completed' => 25]],
                ['milestone_fifty_books_diamond', 'Fifty Books', 'diamond', 'Finish 50 books', ['books_completed' => 50]],
                ['milestone_one_hundred_books_mythic', 'One Hundred Books', 'diamond', 'Finish 100 books', ['books_completed' => 100]],
            ]),
            $this->category('streak', [
                ['streak_3_day_bronze', '3-Day Streak', 'bronze', 'Listen for 3 consecutive days', ['current_streak' => 3]],
                ['streak_7_day_silver', '7-Day Streak', 'silver', 'Listen for 7 consecutive days', ['current_streak' => 7]],
                ['streak_14_day_gold', '14-Day Streak', 'gold', 'Listen for 14 consecutive days', ['current_streak' => 14]],
                ['streak_30_day_platinum', '30-Day Streak', 'platinum', 'Listen for 30 consecutive days', ['current_streak' => 30]],
                ['streak_60_day_diamond', '60-Day Streak', 'diamond', 'Listen for 60 consecutive days', ['current_streak' => 60]],
                ['streak_100_day_mythic', '100-Day Streak', 'diamond', 'Listen for 100 consecutive days', ['current_streak' => 100]],
            ]),
            $this->category('variety', [
                ['variety_3_genres_bronze', '3 Genres Explorer', 'bronze', 'Listen to books from 3 different genres', ['genres_explored' => 3]],
                ['variety_5_genres_silver', '5 Genres Explorer', 'silver', 'Listen to books from 5 different genres', ['genres_explored' => 5]],
                ['variety_8_genres_gold', '8 Genres Explorer', 'gold', 'Listen to books from 8 different genres', ['genres_explored' => 8]],
                ['variety_12_genres_platinum', '12 Genres Explorer', 'platinum', 'Listen to books from 12 different genres', ['genres_explored' => 12]],
                ['variety_multilingual_diamond', 'Multilingual Listener', 'diamond', 'Listen to books in 3 different languages', ['language_variety' => 3]],
                ['variety_new_author_mythic', 'New Author Adventurer', 'diamond', 'Listen to 20 different authors', ['authors_explored' => 20]],
            ]),
            $this->category('social', [
                ['social_first_share_bronze', 'First Share', 'bronze', 'Share a book recommendation', ['community_engagement' => 1]],
                ['social_three_shares_silver', 'Three Shares', 'silver', 'Share 3 book recommendations', ['community_engagement' => 3]],
                ['social_five_reviews_gold', 'Five Reviews', 'gold', 'Review 5 different books', ['books_reviewed' => 5]],
                ['social_ten_reviews_platinum', 'Ten Reviews', 'platinum', 'Review 10 different books', ['books_reviewed' => 10]],
                ['social_helpful_reviews_diamond', 'Helpful Reviewer', 'diamond', 'Receive 10 helpful votes on reviews', ['community_engagement' => 10]],
                ['social_community_star_mythic', 'Community Star', 'diamond', 'Start 10 discussions', ['community_engagement' => 10]],
            ]),
            $this->category('completion', [
                ['completion_first_finish_bronze', 'First Finish', 'bronze', 'Finish a book', ['books_completed' => 1]],
                ['completion_week_finish_silver', 'Finish in a Week', 'silver', 'Finish a book in under 7 days', ['books_completed_this_week' => 1]],
                ['completion_binge_weekend_gold', 'Weekend Binge', 'gold', 'Finish a book over the weekend', ['weekend_listening' => 1]],
                ['completion_no_abandons_platinum', 'Finish What You Start', 'platinum', 'Maintain 100% completion rate for 10 books', ['completion_rate' => 100, 'books_completed' => 10]],
                ['completion_5_in_row_diamond', 'Five in a Row', 'diamond', 'Finish 5 books in a row without starting others', ['books_completed' => 5]],
                ['completion_12_in_month_mythic', 'Dozen in a Month', 'diamond', 'Finish 12 books in one month', ['books_completed_this_month' => 12]],
            ]),
            $this->category('speed', [
                ['speed_fast_start_bronze', 'Fast Start', 'bronze', 'Listen to 1st hour at >1x speed', ['reading_speed' => 110]],
                ['speed_1_25x_silver', '1.25x Speedster', 'silver', 'Listen at 1.25x speed for 5 hours', ['reading_speed' => 125]],
                ['speed_1_5x_gold', '1.5x Speedster', 'gold', 'Listen at 1.5x speed for 10 hours', ['reading_speed' => 150]],
                ['speed_1_75x_platinum', '1.75x Speedster', 'platinum', 'Listen at 1.75x speed for 20 hours', ['reading_speed' => 175]],
                ['speed_2x_diamond', '2x Speed Elite', 'diamond', 'Listen at 2x speed for 50 hours', ['reading_speed' => 200]],
                ['speed_variable_master_mythic', 'Variable Speed Master', 'diamond', 'Use 3 different speeds in one book', ['reading_speed' => 0]], // Custom logic might be needed
            ]),
            $this->category('exploration', [
                ['exploration_new_series_bronze', 'New Series Sampler', 'bronze', 'Start a new series', ['series_completion' => 0]],
                ['exploration_first_series_finish_silver', 'First Series Finish', 'silver', 'Complete a series', ['series_completion' => 1]],
                ['exploration_three_series_gold', 'Three Series Explorer', 'gold', 'Start 3 different series', ['series_completion' => 0]],
                ['exploration_five_series_platinum', 'Five Series Explorer', 'platinum', 'Start 5 different series', ['series_completion' => 0]],
                ['exploration_classics_diamond', 'Classics Explorer', 'diamond', 'Listen to 5 classic books', ['publication_era' => 5]],
                ['exploration_indie_gems_mythic', 'Indie Gems', 'diamond', 'Listen to 10 indie books', ['indie_discovery' => 10]],
            ]),
            $this->category('dedication', [
                ['dedication_morning_routine_bronze', 'Morning Routine', 'bronze', 'Listen in the morning 5 times', ['time_of_day_listening' => 5]],
                ['dedication_evening_routine_silver', 'Evening Routine', 'silver', 'Listen in the evening 10 times', ['time_of_day_listening' => 10]],
                ['dedication_commute_gold', 'Commute Companion', 'gold', 'Listen during commute hours 20 times', ['time_of_day_listening' => 20]],
                ['dedication_weekly_goal_platinum', 'Weekly Goal Keeper', 'platinum', 'Hit weekly goal 4 weeks in a row', ['listening_time_weekly' => 0]], // Needs tracking
                ['dedication_monthly_goal_diamond', 'Monthly Goal Crusher', 'diamond', 'Hit monthly goal 3 months in a row', ['listening_time_monthly' => 0]],
                ['dedication_yearly_goal_mythic', 'Yearly Goal Achiever', 'diamond', 'Hit annual listening goal', ['total_listening_time' => 360000]],
            ]),
            $this->category('discovery', [
                ['discovery_first_wishlist_bronze', 'First Wishlist', 'bronze', 'Add a book to wishlist', ['bookmarks_created' => 1]],
                ['discovery_five_wishlist_silver', 'Five on Wishlist', 'silver', 'Add 5 books to wishlist', ['bookmarks_created' => 5]],
                ['discovery_curator_gold', 'Curator', 'gold', 'Create a public list', ['community_engagement' => 1]],
                ['discovery_sampler_platinum', 'Sampler', 'platinum', 'Listen to 10 samples', ['session_count' => 10]],
                ['discovery_recommendation_diamond', 'Recommendation Pro', 'diamond', 'Read 5 recommended books', ['discovery_rate' => 5]],
                ['discovery_trailblazer_mythic', 'Trailblazer', 'diamond', 'Listen to a book with 0 listens', ['indie_discovery' => 1]],
            ]),
            $this->category('seasonal', [
                ['seasonal_new_year_bronze', 'New Year Kickoff', 'bronze', 'Listen on Jan 1st', ['seasonal_listening' => 1]],
                ['seasonal_spring_refresh_silver', 'Spring Refresh', 'silver', 'Listen in Spring', ['seasonal_listening' => 1]],
                ['seasonal_summer_reading_gold', 'Summer Reading', 'gold', 'Listen in Summer', ['seasonal_listening' => 1]],
                ['seasonal_autumn_stacks_platinum', 'Autumn Stacks', 'platinum', 'Listen in Autumn', ['seasonal_listening' => 1]],
                ['seasonal_winter_warmers_diamond', 'Winter Warmers', 'diamond', 'Listen in Winter', ['seasonal_listening' => 1]],
                ['seasonal_anniversary_mythic', 'Listening Anniversary', 'diamond', 'Listen on your anniversay', ['seasonal_listening' => 1]],
            ]),
            $this->category('collection', [
                ['collection_first_library_bronze', 'First Library', 'bronze', 'Have 1 book in library', ['library_size' => 1]],
                ['collection_25_library_silver', '25 in Library', 'silver', 'Have 25 books in library', ['library_size' => 25]],
                ['collection_50_library_gold', '50 in Library', 'gold', 'Have 50 books in library', ['library_size' => 50]],
                ['collection_100_library_platinum', '100 in Library', 'platinum', 'Have 100 books in library', ['library_size' => 100]],
                ['collection_series_complete_diamond', 'Series Complete', 'diamond', 'Collect all books in a series', ['series_completion' => 1]],
                ['collection_curated_sets_mythic', 'Curated Sets', 'diamond', 'Create 5 collections', ['community_engagement' => 5]],
            ]),
            $this->category('habit', [
                ['habit_first_week_bronze', 'Habit Starter', 'bronze', 'Listen for 1 week consecutive', ['listening_streak' => 7]],
                ['habit_two_weeks_silver', 'Two Weeks Habit', 'silver', 'Listen for 2 weeks consecutive', ['listening_streak' => 14]],
                ['habit_month_streak_gold', 'Month Habit', 'gold', 'Listen for 1 month consecutive', ['listening_streak' => 30]],
                ['habit_three_months_platinum', 'Three Months Habit', 'platinum', 'Listen for 3 months consecutive', ['listening_streak' => 90]],
                ['habit_six_months_diamond', 'Six Months Habit', 'diamond', 'Listen for 6 months consecutive', ['listening_streak' => 180]],
                ['habit_year_habit_mythic', 'Year Habit', 'diamond', 'Listen for 1 year consecutive', ['listening_streak' => 365]],
            ]),
        );
    }

    private function category(string $category, array $items): array
    {
        return array_map(function (array $i) use ($category) {
            $tiers = [
                'bronze' => 10,
                'silver' => 25,
                'gold' => 50,
                'platinum' => 100,
                'diamond' => 250
            ];

            return [
                'key' => $i[0],
                'name' => $i[1],
                'category' => $category,
                'tier' => $i[2],
                'description' => $i[3] ?? '',
                'criteria' => $i[4] ?? [],
                'points' => $tiers[$i[2]] ?? 0,
            ];
        }, $items);
    }

    private function emojiForCategory(string $category): string
    {
        $map = [
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
        ];

        return $map[$category] ?? '🏆';
    }
}
