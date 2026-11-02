<?php

namespace Tests\Feature;

use App\Services\PublerPublisher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScheduledPostsPaginationTest extends TestCase
{
    public function test_it_paginates_until_an_empty_page(): void
    {
        config(['services.publer.api_key' => 'test-key', 'services.publer.workspace_id' => 'ws']);

        // Publer levert posts per pagina; de gezochte post staat pas op pagina 2.
        // Een niet-pagineerende implementatie zou 'm missen.
        Http::fake(function ($request) {
            $page = (int) ($request->data()['page'] ?? 1);

            $posts = match ($page) {
                1 => [[
                    'id' => 'p1', 'account_id' => 'acc-a', 'group_id' => 'g1',
                    'text' => 'eerste', 'type' => 'status',
                    'scheduled_at' => '2026-11-10T07:30:00.000+01:00', 'media' => [],
                ]],
                2 => [[
                    'id' => 'p2', 'account_id' => 'acc-a', 'group_id' => 'g2',
                    'text' => 'ver weg', 'type' => 'status',
                    'scheduled_at' => '2026-11-24T07:30:00.000+01:00', 'media' => [],
                ]],
                default => [],
            };

            return Http::response(['posts' => $posts], 200);
        });

        $out = (new PublerPublisher())->scheduledPostsForAccounts(['acc-a']);

        $ids = array_column($out, 'id');
        $this->assertContains('p1', $ids);
        $this->assertContains('p2', $ids, 'Post op pagina 2 moet ook gevonden worden.');
    }

    public function test_it_stops_and_returns_nothing_when_first_page_fails(): void
    {
        config(['services.publer.api_key' => 'test-key', 'services.publer.workspace_id' => 'ws']);

        Http::fake(fn () => Http::response('error', 500));

        $out = (new PublerPublisher())->scheduledPostsForAccounts(['acc-a']);

        $this->assertSame([], $out);
    }
}
