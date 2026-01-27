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
            // ... (other sets)
        ];

        return array_merge(...array_values($sets));
    }
}
