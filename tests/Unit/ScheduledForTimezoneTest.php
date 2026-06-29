<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ContentItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledForTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_for_is_stored_in_utc_and_reads_back_consistent(): void
    {
        $client = Client::factory()->create();

        // 07:30 Europe/Amsterdam zou 05:30 UTC moeten worden in de DB.
        $amsterdamTime = Carbon::create(2026, 7, 1, 7, 30, 0, 'Europe/Amsterdam');

        $item = ContentItem::create([
            'client_id'     => $client->id,
            'title'         => 'Slot test',
            'brief'         => 'Slot brief',
            'scheduled_for' => $amsterdamTime,
        ]);

        // Raw DB value moet UTC zijn: 05:30:00
        $this->assertSame('2026-07-01 05:30:00', $item->fresh()->getRawOriginal('scheduled_for'));

        // Teruggelezen en geconverteerd naar Amsterdam: weer 07:30
        $this->assertSame(
            '2026-07-01 07:30:00',
            $item->fresh()->scheduled_for->copy()->setTimezone('Europe/Amsterdam')->format('Y-m-d H:i:s')
        );
    }
}
