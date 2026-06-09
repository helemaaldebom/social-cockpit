<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\PublishSlot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_slot_is_always_active(): void
    {
        $client = Client::factory()->create();
        $slot = PublishSlot::factory()->create([
            'client_id' => $client->id,
            'interval_weeks' => 1,
            'active' => true,
        ]);

        $this->assertTrue($slot->isActiveOnDate(Carbon::now()));
        $this->assertTrue($slot->isActiveOnDate(Carbon::now()->addWeeks(3)));
    }

    public function test_biweekly_slot_alternates(): void
    {
        $client = Client::factory()->create();
        $monday = Carbon::parse('2024-01-01'); // A Monday

        $slot = PublishSlot::factory()->create([
            'client_id' => $client->id,
            'interval_weeks' => 2,
            'reference_date' => $monday->toDateString(),
            'active' => true,
        ]);

        // Same week as reference = active (0 mod 2 = 0)
        $this->assertTrue($slot->isActiveOnDate($monday));

        // One week later = not active (1 mod 2 = 1)
        $this->assertFalse($slot->isActiveOnDate($monday->copy()->addWeek()));

        // Two weeks later = active (2 mod 2 = 0)
        $this->assertTrue($slot->isActiveOnDate($monday->copy()->addWeeks(2)));
    }

    public function test_biweekly_without_reference_date_returns_false(): void
    {
        $client = Client::factory()->create();
        $slot = PublishSlot::factory()->create([
            'client_id' => $client->id,
            'interval_weeks' => 2,
            'reference_date' => null,
            'active' => true,
        ]);

        $this->assertFalse($slot->isActiveOnDate(Carbon::now()));
    }

    public function test_inactive_slot_returns_false(): void
    {
        $client = Client::factory()->create();
        $slot = PublishSlot::factory()->create([
            'client_id' => $client->id,
            'active' => false,
        ]);

        $this->assertFalse($slot->isActiveOnDate(Carbon::now()));
    }
}
