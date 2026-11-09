<?php

namespace Tests\Feature;

use App\Services\PublerPublisher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScheduledPostsPaginationTest extends TestCase
{
    public function test_it_paginates_and_filters_published_out(): void
    {
        config(['services.publer.api_key' => 'test-key', 'services.publer.workspace_id' => 'ws']);

        // De ongefilterde lijst mengt scheduled + published. De gezochte
        // scheduled-post staat pas op pagina 2; pagina 3 is puur historie
        // (geen scheduled meer) en moet de lus stoppen.
        Http::fake(function ($request) {
            $page = (int) ($request->data()['page'] ?? 1);

            $posts = match ($page) {
                1 => [[
                    'id' => 'p1', 'account_id' => 'acc-a', 'group_id' => 'g1',
                    'text' => 'eerste', 'type' => 'status', 'state' => 'scheduled',
                    'scheduled_at' => '2026-11-10T07:30:00.000+01:00', 'media' => [],
                ]],
                2 => [[
                    'id' => 'p2', 'account_id' => 'acc-a', 'group_id' => 'g2',
                    'text' => 'ver weg', 'type' => 'status', 'state' => 'scheduled',
                    'scheduled_at' => '2026-11-24T07:30:00.000+01:00', 'media' => [],
                ], [
                    'id' => 'oud', 'account_id' => 'acc-a', 'group_id' => 'g9',
                    'text' => 'gepubliceerd', 'type' => 'status', 'state' => 'published',
                    'scheduled_at' => '2026-10-01T07:30:00.000+02:00', 'media' => [],
                ]],
                3 => [[
                    'id' => 'oud2', 'account_id' => 'acc-a', 'group_id' => 'g8',
                    'text' => 'ouder', 'type' => 'status', 'state' => 'published',
                    'scheduled_at' => '2026-09-01T07:30:00.000+02:00', 'media' => [],
                ]],
                default => [],
            };

            return Http::response(['posts' => $posts], 200);
        });

        $out = (new PublerPublisher())->scheduledPostsForAccounts(['acc-a']);

        $ids = array_column($out, 'id');
        $this->assertContains('p1', $ids);
        $this->assertContains('p2', $ids, 'Scheduled post op pagina 2 moet gevonden worden.');
        $this->assertNotContains('oud', $ids, 'Published posts horen eruit gefilterd te worden.');
        $this->assertNotContains('oud2', $ids);
    }

    public function test_it_stops_and_returns_nothing_when_first_page_fails(): void
    {
        config(['services.publer.api_key' => 'test-key', 'services.publer.workspace_id' => 'ws']);

        Http::fake(fn () => Http::response('error', 500));

        $out = (new PublerPublisher())->scheduledPostsForAccounts(['acc-a']);

        $this->assertSame([], $out);
    }
}
