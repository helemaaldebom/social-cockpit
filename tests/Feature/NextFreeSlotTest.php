<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\PublishSlot;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NextFreeSlotTest extends TestCase
{
    use RefreshDatabase;

    private function ztsLikeClient(): Client
    {
        $client = Client::factory()->create();
        PublishSlot::create([
            'client_id'      => $client->id,
            'day_of_week'    => 2, // dinsdag
            'time'           => '07:30:00',
            'timezone'       => 'Europe/Amsterdam',
            'interval_weeks' => 1,
            'active'         => true,
        ]);
        PublishSlot::create([
            'client_id'      => $client->id,
            'day_of_week'    => 5, // vrijdag
            'time'           => '07:30:00',
            'timezone'       => 'Europe/Amsterdam',
            'interval_weeks' => 1,
            'active'         => true,
        ]);
        return $client;
    }

    public function test_returns_earliest_free_slot_when_none_taken(): void
    {
        $client = $this->ztsLikeClient();

        // Maandag 12:00 → eerstvolgend slot = dinsdag 07:30
        $monday = Carbon::create(2026, 7, 6, 12, 0, 0, 'Europe/Amsterdam'); // dit is maandag
        $slot = $client->nextFreeSlot($monday);

        $this->assertNotNull($slot);
        $this->assertSame('Tuesday', $slot->format('l'));
        $this->assertSame('07:30', $slot->setTimezone('Europe/Amsterdam')->format('H:i'));
    }

    public function test_skips_taken_slot_to_next_free(): void
    {
        $client = $this->ztsLikeClient();

        $monday = Carbon::create(2026, 7, 6, 12, 0, 0, 'Europe/Amsterdam');

        // Dinsdag 07:30 is al bezet
        $tuesday = Carbon::create(2026, 7, 7, 7, 30, 0, 'Europe/Amsterdam');
        ContentItem::create([
            'client_id'      => $client->id,
            'title'          => 'Bestaand',
            'brief'          => 'Brief',
            'status'         => 'ingepland',
            'scheduled_for'  => $tuesday,
        ]);

        $slot = $client->nextFreeSlot($monday);

        // Vrijdag is volgend vrij slot
        $this->assertSame('Friday', $slot->format('l'));
    }

    public function test_skips_multiple_taken_slots(): void
    {
        $client = $this->ztsLikeClient();

        $monday = Carbon::create(2026, 7, 6, 12, 0, 0, 'Europe/Amsterdam');

        // Dinsdag + vrijdag deze week bezet
        foreach ([
            Carbon::create(2026, 7, 7, 7, 30, 0, 'Europe/Amsterdam'),
            Carbon::create(2026, 7, 10, 7, 30, 0, 'Europe/Amsterdam'),
        ] as $when) {
            ContentItem::create([
                'client_id'     => $client->id,
                'title'         => 'Bestaand',
                'brief'         => 'Brief',
                'status'        => 'ingepland',
                'scheduled_for' => $when,
            ]);
        }

        $slot = $client->nextFreeSlot($monday);

        // Volgend dinsdag (week erop) zou de eerstvolgende vrije zijn
        $this->assertSame('Tuesday', $slot->format('l'));
        $this->assertSame('2026-07-14', $slot->format('Y-m-d'));
    }

    public function test_returns_null_when_client_has_no_active_slots(): void
    {
        $client = Client::factory()->create();
        $this->assertNull($client->nextFreeSlot());
    }
}
