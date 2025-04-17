<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Author;
use App\Models\Series;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Follow>
 */
class FollowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $followable = fake()->randomElement([Author::factory(), Series::factory()]);
        $followableInstance = $followable->create();

        return [
            'user_id' => User::factory(),
            'followable_type' => $followableInstance::class,
            'followable_id' => $followableInstance->id,
        ];
    }
}
