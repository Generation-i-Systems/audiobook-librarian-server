<?php

namespace Database\Factories;

use App\Models\Author;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Author> */
class AuthorFactory extends Factory
{
    protected $model = Author::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'biography' => $this->faker->optional()->paragraph(),
            'image_url' => $this->faker->optional()->imageUrl(),
        ];
    }
}
