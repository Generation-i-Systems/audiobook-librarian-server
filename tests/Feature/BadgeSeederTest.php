<?php

namespace Tests\Feature;

use App\Models\Badge;
use Database\Seeders\CanonicalBadgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function testCanonicalBadgeSeederCreates78BadgesWithExpectedStructure(): void
    {
        $this->seed(CanonicalBadgeSeeder::class);

        $expectedKeys = $this->canonicalKeys();

        // Ensure exactly 78 canonical keys exist (distinct by key)
        $foundCount = Badge::whereIn('key', $expectedKeys)->pluck('key')->unique()->count();
        $this->assertSame(78, $foundCount, 'Expected 78 distinct canonical badge keys to exist');

        // Idempotency: running the seeder again should not change distinct count
        $this->seed(CanonicalBadgeSeeder::class);
        $foundCount2 = Badge::whereIn('key', $expectedKeys)->pluck('key')->unique()->count();
        $this->assertSame(78, $foundCount2, 'Seeder should be idempotent on canonical badges');

        // Ensure there are no duplicates in canonical keys
        $this->assertSame(78, count(array_unique($expectedKeys)), 'Canonical key list contains duplicates');

        // Every expected key exists
        foreach ($expectedKeys as $key) {
            $this->assertTrue(Badge::where('key', $key)->exists(), "Missing badge key: {$key}");
        }

        // Validate categories and tiers are within allowed sets
        $allowedCategories = array_keys(Badge::CATEGORIES);
        $allowedTiers = array_keys(Badge::TIERS);

        Badge::all()->each(function (Badge $b) use ($allowedCategories, $allowedTiers) {
            $this->assertContains($b->category, $allowedCategories, "Invalid category on {$b->key}");
            $this->assertContains($b->tier, $allowedTiers, "Invalid tier on {$b->key}");
            // @phpstan-ignore-next-line
            $this->assertTrue(is_bool($b->is_active));
            // @phpstan-ignore-next-line
            $this->assertTrue(is_bool($b->is_repeatable));
            // @phpstan-ignore-next-line
            $this->assertTrue(is_int($b->sort_order));

            // icon expectations (emoji stored in 'icon', SVG URI stored in 'image_url')
            $this->assertIsString($b->icon);
            $this->assertNotSame('', (string) $b->icon, 'icon should not be empty');
            $this->assertIsString($b->image_url);
            $this->assertNotSame('', (string) $b->image_url, 'image_url should not be empty');
            $this->assertStringEndsWith('.svg', $b->image_url, 'image_url should point to an SVG');
            $this->assertStringContainsString($b->key, $b->image_url, 'image_url should include the key');
        });
    }

    public function testCanonicalBadgesOnlyUseSupportedCriteriaAndAvoidImpossibleWishlistBadges(): void
    {
        $this->seed(CanonicalBadgeSeeder::class);

        $supportedCriteria = [
            'total_listening_time',
            'weekend_listening_time',
            'current_streak',
            'books_completed',
            'genres_explored',
            'language_variety',
            'authors_explored',
            'recommendations_sent',
            'books_reviewed',
            'quick_finishes',
            'books_completed_on_weekend',
            'completion_rate',
            'books_completed_this_month',
            'speed_time_110',
            'speed_time_125',
            'speed_time_150',
            'speed_time_175',
            'speed_time_200',
            'speed_variety',
            'series_explored',
            'series_completion',
            'classic_books_explored',
            'indie_books_explored',
            'morning_sessions',
            'evening_sessions',
            'commute_sessions',
            'weekly_goal_streak',
            'monthly_goal_streak',
            'yearly_goal_achieved',
            'bookmarks_created',
            'playlist_count',
            'books_started',
            'discovery_rate',
            'new_year_sessions',
            'spring_sessions',
            'summer_sessions',
            'autumn_sessions',
            'winter_sessions',
            'anniversary_sessions',
            'library_size',
        ];

        Badge::query()->get()->each(function (Badge $badge) use ($supportedCriteria): void {
            foreach (array_keys($badge->criteria) as $criterion) {
                $this->assertContains($criterion, $supportedCriteria, "Unsupported criterion {$criterion} on {$badge->key}");
            }

            $this->assertStringNotContainsString('Wishlist', $badge->name, "Impossible wishlist badge still present on {$badge->key}");
        });
    }

    public function testBadgeProgressPercentageUsesPartialProgressForNumericCriteria(): void
    {
        $badge = Badge::create([
            'key' => 'progress_test_badge',
            'name' => 'Progress Test Badge',
            'description' => 'Test badge progress math',
            'icon' => 'test',
            'image_url' => '/images/badges/progress_test_badge.svg',
            'category' => 'listening',
            'tier' => 'bronze',
            'points' => 10,
            'criteria' => [
                'books_completed' => 4,
                'library_size' => 2,
            ],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 1,
        ]);

        $progress = $badge->getProgressPercentage([
            'books_completed' => 2,
            'library_size' => 1,
        ]);

        $this->assertSame(50, $progress);
    }

    private function canonicalKeys(): array
    {
        $sets = [
            'listening' => [
                'listening_starter_bronze',
                'listening_weekend_listener_silver',
                'listening_daily_listener_gold',
                'listening_100_hours_platinum',
                'listening_250_hours_diamond',
                'listening_500_hours_mythic',
            ],
            'milestone' => [
                'milestone_first_book_bronze',
                'milestone_five_books_silver',
                'milestone_ten_books_gold',
                'milestone_twentyfive_books_platinum',
                'milestone_fifty_books_diamond',
                'milestone_one_hundred_books_mythic',
            ],
            'streak' => [
                'streak_3_day_bronze',
                'streak_7_day_silver',
                'streak_14_day_gold',
                'streak_30_day_platinum',
                'streak_60_day_diamond',
                'streak_100_day_mythic',
            ],
            'variety' => [
                'variety_3_genres_bronze',
                'variety_5_genres_silver',
                'variety_8_genres_gold',
                'variety_12_genres_platinum',
                'variety_multilingual_diamond',
                'variety_new_author_mythic',
            ],
            'social' => [
                'social_first_share_bronze',
                'social_three_shares_silver',
                'social_five_reviews_gold',
                'social_ten_reviews_platinum',
                'social_helpful_reviews_diamond',
                'social_community_star_mythic',
            ],
            'completion' => [
                'completion_first_finish_bronze',
                'completion_week_finish_silver',
                'completion_binge_weekend_gold',
                'completion_no_abandons_platinum',
                'completion_5_in_row_diamond',
                'completion_12_in_month_mythic',
            ],
            'speed' => [
                'speed_fast_start_bronze',
                'speed_1_25x_silver',
                'speed_1_5x_gold',
                'speed_1_75x_platinum',
                'speed_2x_diamond',
                'speed_variable_master_mythic',
            ],
            'exploration' => [
                'exploration_new_series_bronze',
                'exploration_first_series_finish_silver',
                'exploration_three_series_gold',
                'exploration_five_series_platinum',
                'exploration_classics_diamond',
                'exploration_indie_gems_mythic',
            ],
            'dedication' => [
                'dedication_morning_routine_bronze',
                'dedication_evening_routine_silver',
                'dedication_commute_gold',
                'dedication_weekly_goal_platinum',
                'dedication_monthly_goal_diamond',
                'dedication_yearly_goal_mythic',
            ],
            'discovery' => [
                'discovery_first_wishlist_bronze',
                'discovery_five_wishlist_silver',
                'discovery_curator_gold',
                'discovery_sampler_platinum',
                'discovery_recommendation_diamond',
                'discovery_trailblazer_mythic',
            ],
            'seasonal' => [
                'seasonal_new_year_bronze',
                'seasonal_spring_refresh_silver',
                'seasonal_summer_reading_gold',
                'seasonal_autumn_stacks_platinum',
                'seasonal_winter_warmers_diamond',
                'seasonal_anniversary_mythic',
            ],
            'collection' => [
                'collection_first_library_bronze',
                'collection_25_library_silver',
                'collection_50_library_gold',
                'collection_100_library_platinum',
                'collection_series_complete_diamond',
                'collection_curated_sets_mythic',
            ],
            'habit' => [
                'habit_first_week_bronze',
                'habit_two_weeks_silver',
                'habit_month_streak_gold',
                'habit_three_months_platinum',
                'habit_six_months_diamond',
                'habit_year_habit_mythic',
            ],
        ];

        return array_merge(...array_values($sets));
    }
}
