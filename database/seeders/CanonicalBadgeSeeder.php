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
            $data = array_merge([
                'description' => '',
                'icon' => null,
                'image_url' => null,
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
                ['listening_starter_bronze', 'Listening Starter', 'bronze'],
                ['listening_weekend_listener_silver', 'Weekend Listener', 'silver'],
                ['listening_daily_listener_gold', 'Daily Listener', 'gold'],
                ['listening_100_hours_platinum', '100 Hours', 'platinum'],
                ['listening_250_hours_diamond', '250 Hours', 'diamond'],
                ['listening_500_hours_mythic', '500 Hours', 'diamond'],
            ]),
            $this->category('milestone', [
                ['milestone_first_book_bronze', 'First Book', 'bronze'],
                ['milestone_five_books_silver', 'Five Books', 'silver'],
                ['milestone_ten_books_gold', 'Ten Books', 'gold'],
                ['milestone_twentyfive_books_platinum', 'Twenty-Five Books', 'platinum'],
                ['milestone_fifty_books_diamond', 'Fifty Books', 'diamond'],
                ['milestone_one_hundred_books_mythic', 'One Hundred Books', 'diamond'],
            ]),
            $this->category('streak', [
                ['streak_3_day_bronze', '3-Day Streak', 'bronze'],
                ['streak_7_day_silver', '7-Day Streak', 'silver'],
                ['streak_14_day_gold', '14-Day Streak', 'gold'],
                ['streak_30_day_platinum', '30-Day Streak', 'platinum'],
                ['streak_60_day_diamond', '60-Day Streak', 'diamond'],
                ['streak_100_day_mythic', '100-Day Streak', 'diamond'],
            ]),
            $this->category('variety', [
                ['variety_3_genres_bronze', '3 Genres Explorer', 'bronze'],
                ['variety_5_genres_silver', '5 Genres Explorer', 'silver'],
                ['variety_8_genres_gold', '8 Genres Explorer', 'gold'],
                ['variety_12_genres_platinum', '12 Genres Explorer', 'platinum'],
                ['variety_multilingual_diamond', 'Multilingual Listener', 'diamond'],
                ['variety_new_author_mythic', 'New Author Adventurer', 'diamond'],
            ]),
            $this->category('social', [
                ['social_first_share_bronze', 'First Share', 'bronze'],
                ['social_three_shares_silver', 'Three Shares', 'silver'],
                ['social_five_reviews_gold', 'Five Reviews', 'gold'],
                ['social_ten_reviews_platinum', 'Ten Reviews', 'platinum'],
                ['social_helpful_reviews_diamond', 'Helpful Reviewer', 'diamond'],
                ['social_community_star_mythic', 'Community Star', 'diamond'],
            ]),
            $this->category('completion', [
                ['completion_first_finish_bronze', 'First Finish', 'bronze'],
                ['completion_week_finish_silver', 'Finish in a Week', 'silver'],
                ['completion_binge_weekend_gold', 'Weekend Binge', 'gold'],
                ['completion_no_abandons_platinum', 'Finish What You Start', 'platinum'],
                ['completion_5_in_row_diamond', 'Five in a Row', 'diamond'],
                ['completion_12_in_month_mythic', 'Dozen in a Month', 'diamond'],
            ]),
            $this->category('speed', [
                ['speed_fast_start_bronze', 'Fast Start', 'bronze'],
                ['speed_1_25x_silver', '1.25x Speedster', 'silver'],
                ['speed_1_5x_gold', '1.5x Speedster', 'gold'],
                ['speed_1_75x_platinum', '1.75x Speedster', 'platinum'],
                ['speed_2x_diamond', '2x Speed Elite', 'diamond'],
                ['speed_variable_master_mythic', 'Variable Speed Master', 'diamond'],
            ]),
            $this->category('exploration', [
                ['exploration_new_series_bronze', 'New Series Sampler', 'bronze'],
                ['exploration_first_series_finish_silver', 'First Series Finish', 'silver'],
                ['exploration_three_series_gold', 'Three Series Explorer', 'gold'],
                ['exploration_five_series_platinum', 'Five Series Explorer', 'platinum'],
                ['exploration_classics_diamond', 'Classics Explorer', 'diamond'],
                ['exploration_indie_gems_mythic', 'Indie Gems', 'diamond'],
            ]),
            $this->category('dedication', [
                ['dedication_morning_routine_bronze', 'Morning Routine', 'bronze'],
                ['dedication_evening_routine_silver', 'Evening Routine', 'silver'],
                ['dedication_commute_gold', 'Commute Companion', 'gold'],
                ['dedication_weekly_goal_platinum', 'Weekly Goal Keeper', 'platinum'],
                ['dedication_monthly_goal_diamond', 'Monthly Goal Crusher', 'diamond'],
                ['dedication_yearly_goal_mythic', 'Yearly Goal Achiever', 'diamond'],
            ]),
            $this->category('discovery', [
                ['discovery_first_wishlist_bronze', 'First Wishlist', 'bronze'],
                ['discovery_five_wishlist_silver', 'Five on Wishlist', 'silver'],
                ['discovery_curator_gold', 'Curator', 'gold'],
                ['discovery_sampler_platinum', 'Sampler', 'platinum'],
                ['discovery_recommendation_diamond', 'Recommendation Pro', 'diamond'],
                ['discovery_trailblazer_mythic', 'Trailblazer', 'diamond'],
            ]),
            $this->category('seasonal', [
                ['seasonal_new_year_bronze', 'New Year Kickoff', 'bronze'],
                ['seasonal_spring_refresh_silver', 'Spring Refresh', 'silver'],
                ['seasonal_summer_reading_gold', 'Summer Reading', 'gold'],
                ['seasonal_autumn_stacks_platinum', 'Autumn Stacks', 'platinum'],
                ['seasonal_winter_warmers_diamond', 'Winter Warmers', 'diamond'],
                ['seasonal_anniversary_mythic', 'Listening Anniversary', 'diamond'],
            ]),
            $this->category('collection', [
                ['collection_first_library_bronze', 'First Library', 'bronze'],
                ['collection_25_library_silver', '25 in Library', 'silver'],
                ['collection_50_library_gold', '50 in Library', 'gold'],
                ['collection_100_library_platinum', '100 in Library', 'platinum'],
                ['collection_series_complete_diamond', 'Series Complete', 'diamond'],
                ['collection_curated_sets_mythic', 'Curated Sets', 'diamond'],
            ]),
            $this->category('habit', [
                ['habit_first_week_bronze', 'Habit Starter', 'bronze'],
                ['habit_two_weeks_silver', 'Two Weeks Habit', 'silver'],
                ['habit_month_streak_gold', 'Month Habit', 'gold'],
                ['habit_three_months_platinum', 'Three Months Habit', 'platinum'],
                ['habit_six_months_diamond', 'Six Months Habit', 'diamond'],
                ['habit_year_habit_mythic', 'Year Habit', 'diamond'],
            ]),
        );
    }

    private function category(string $category, array $items): array
    {
        return array_map(function (array $i) use ($category) {
            return [
                'key' => $i[0],
                'name' => $i[1],
                'category' => $category,
                'tier' => $i[2],
            ];
        }, $items);
    }
}
