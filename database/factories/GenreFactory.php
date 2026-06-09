<?php

namespace Database\Factories;

use App\Models\Genre;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Genre> */
class GenreFactory extends Factory
{
    protected $model = Genre::class;

    public function definition(): array
    {
        $genres = [
            'Fantasy', 'Science Fiction', 'Mystery', 'Thriller', 'Romance',
            'Historical Fiction', 'Biography', 'Self-Help', 'Business',
            'Horror', 'Adventure', 'Comedy', 'Drama', 'Non-Fiction'
        ];

        return [
            'name' => $this->faker->unique()->randomElement($genres),
            'emoji' => null,
            'icon_path' => null,
        ];
    }
}
