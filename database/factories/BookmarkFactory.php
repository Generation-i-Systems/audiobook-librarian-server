<?php

namespace Database\Factories;

use App\Models\Bookmark;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @template TModel of \App\Models\Bookmark
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<TModel>
 */
class BookmarkFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TModel>
     */
    protected $model = Bookmark::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'book_id' => \App\Models\Book::factory(),
            'title' => $this->faker->sentence(),
            'chapter' => (string) $this->faker->numberBetween(1, 20),
            'position' => $this->faker->numberBetween(0, 3600),
            'notes' => $this->faker->paragraph(),
            'is_auto' => false,
        ];
    }
}
