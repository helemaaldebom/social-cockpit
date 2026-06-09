<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => $this->faker->sentence(4),
            'brief' => $this->faker->paragraph(),
            'status' => ContentStatus::Concept->value,
        ];
    }
}
