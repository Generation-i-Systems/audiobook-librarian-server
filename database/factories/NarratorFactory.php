<?php

namespace Database\Factories;

use App\Models\Narrator;
use Illuminate\Database\Eloquent\Factories\Factory;

class NarratorFactory extends Factory
{
    protected $model = Narrator::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}
