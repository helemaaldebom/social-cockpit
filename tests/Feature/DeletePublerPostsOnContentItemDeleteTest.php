<?php

namespace Tests\Feature;

use App\Jobs\DeletePublerPostsJob;
use App\Models\Client;
use App\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeletePublerPostsOnContentItemDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_content_item_dispatches_publer_delete_job(): void
    {
        Queue::fake();
        $client = Client::factory()->create();

        $item = ContentItem::create([
            'client_id'        => $client->id,
            'title'            => 'Te verwijderen',
            'brief'            => 'Brief',
            'publer_post_id'   => 'abc123',
            'publer_post_ids'  => ['abc123', 'def456', 'ghi789'],
        ]);

        $item->delete();

        Queue::assertPushed(DeletePublerPostsJob::class, function (DeletePublerPostsJob $job) use ($item) {
            return $job->contentItemId === $item->id
                && $job->publerPostIds === ['abc123', 'def456', 'ghi789'];
        });
    }

    public function test_deleting_content_item_without_publer_ids_does_not_dispatch(): void
    {
        Queue::fake();
        $client = Client::factory()->create();

        $item = ContentItem::create([
            'client_id' => $client->id,
            'title'     => 'Concept zonder publer',
            'brief'     => 'Brief',
        ]);

        $item->delete();

        Queue::assertNotPushed(DeletePublerPostsJob::class);
    }

    public function test_deleting_item_with_only_legacy_publer_post_id_dispatches_that_single_id(): void
    {
        Queue::fake();
        $client = Client::factory()->create();

        $item = ContentItem::create([
            'client_id'      => $client->id,
            'title'          => 'Legacy',
            'brief'          => 'Brief',
            'publer_post_id' => 'legacy-only',
            // publer_post_ids null — komt voor bij items van vóór de multi-id fix
        ]);

        $item->delete();

        Queue::assertPushed(DeletePublerPostsJob::class, function (DeletePublerPostsJob $job) {
            return $job->publerPostIds === ['legacy-only'];
        });
    }
}
