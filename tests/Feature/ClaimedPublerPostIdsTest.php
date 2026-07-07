<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClaimedPublerPostIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_ids_of_other_items_including_trashed(): void
    {
        Queue::fake();
        $client = Client::factory()->create();

        $a = ContentItem::create([
            'client_id' => $client->id, 'title' => 'A', 'brief' => 'b',
            'publer_post_ids' => ['aaa', 'bbb'],
        ]);
        $b = ContentItem::create([
            'client_id' => $client->id, 'title' => 'B', 'brief' => 'b',
            'publer_post_ids' => ['ccc'],
        ]);
        $b->delete(); // soft-deleted items blijven meetellen

        $claimed = ContentItem::claimedPublerPostIds();
        sort($claimed);
        $this->assertSame(['aaa', 'bbb', 'ccc'], $claimed);
    }

    public function test_except_item_id_excludes_own_ids(): void
    {
        $client = Client::factory()->create();

        $a = ContentItem::create([
            'client_id' => $client->id, 'title' => 'A', 'brief' => 'b',
            'publer_post_ids' => ['aaa', 'bbb'],
        ]);
        ContentItem::create([
            'client_id' => $client->id, 'title' => 'B', 'brief' => 'b',
            'publer_post_ids' => ['ccc'],
        ]);

        $this->assertSame(['ccc'], ContentItem::claimedPublerPostIds($a->id));
    }
}
