<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\User;
use App\Models\UserRecommendation;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserRecommendationFactory extends Factory
{
    protected $model = UserRecommendation::class;

    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            // Ensure recipient is different from sender (requires two separate users)
            'recipient_id' => User::factory(),
            'book_id' => Book::factory(),
            'message' => $this->faker->optional(0.7)->sentence(),
            'acknowledged_at' => null,
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'updated_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
