<?php

namespace Database\Factories;

use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Book>
     */
    protected $model = Book::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'release_date' => $this->faker->date(),
            'cover_image' => null,
            'language' => 'en',
            'source' => 'test',
            'directory_path' => $this->faker->word(),
            'duration' => $this->faker->numberBetween(3600, 36000),
            'publisher_id' => null,
            'needs_review' => false,
            'audio_file_count' => $this->faker->numberBetween(1, 20),
            'isbn' => $this->faker->isbn13(),
        ];
    }
}
