<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Http\Controllers\Api\UserStatusController;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserBookStatusFactory extends Factory
{
    protected $model = UserBookStatus::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'book_id' => Book::factory(),
            'status' => $this->faker->randomElement(UserStatusController::VALID_STATUSES),
            'order' => $this->faker->unique()->numberBetween(1, 100),
            'read_count' => $this->faker->numberBetween(0, 5),
            'status_detail' => ['note' => $this->faker->sentence()],
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'updated_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
