<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class PublishSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'day_of_week' => $this->faker->numberBetween(1, 7),
            'time' => '09:00:00',
            'timezone' => 'Europe/Amsterdam',
            'interval_weeks' => 1,
            'reference_date' => null,
            'active' => true,
        ];
    }
}
