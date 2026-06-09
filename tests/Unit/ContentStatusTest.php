<?php

namespace Tests\Unit;

use App\Enums\ContentStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Client;
use App\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(ContentStatus $status): ContentItem
    {
        $client = Client::factory()->create();
        return ContentItem::factory()->create([
            'client_id' => $client->id,
            'status' => $status->value,
        ]);
    }

    public function test_valid_transitions(): void
    {
        $item = $this->makeItem(ContentStatus::Concept);

        $item->changeStatus(ContentStatus::Gegenereerd);
        $this->assertEquals(ContentStatus::Gegenereerd, $item->fresh()->status);

        $item->changeStatus(ContentStatus::InReview);
        $item->changeStatus(ContentStatus::Goedgekeurd);
        $item->changeStatus(ContentStatus::Ingepland);
        $item->changeStatus(ContentStatus::Geplaatst);

        $this->assertEquals(ContentStatus::Geplaatst, $item->fresh()->status);
    }

    public function test_any_status_can_go_to_mislukt(): void
    {
        $item = $this->makeItem(ContentStatus::Concept);
        $item->changeStatus(ContentStatus::Mislukt, 'Testfout');

        $this->assertEquals(ContentStatus::Mislukt, $item->fresh()->status);
    }

    public function test_invalid_transition_throws(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);

        $item = $this->makeItem(ContentStatus::Concept);
        $item->changeStatus(ContentStatus::Geplaatst);
    }

    public function test_audit_log_written_on_transition(): void
    {
        $item = $this->makeItem(ContentStatus::Concept);
        $item->changeStatus(ContentStatus::Gegenereerd, 'Test note');

        $log = $item->logs()->first();
        $this->assertNotNull($log);
        $this->assertEquals(ContentStatus::Concept->value, $log->from_status);
        $this->assertEquals(ContentStatus::Gegenereerd->value, $log->to_status);
        $this->assertEquals('Test note', $log->note);
    }
}
