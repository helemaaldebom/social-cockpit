<?php

namespace Tests\Feature;

use App\Services\PublerPublisher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScheduledPostsPaginationTest extends TestCase
{
    private function fakePost(string $id, string $at, string $state = 'scheduled'): array
    {
        return [
            'id' => $id, 'account_id' => 'acc-a', 'group_id' => 'g' . $id,
            'text' => $id, 'type' => 'status', 'state' => $state,
            'scheduled_at' => $at, 'media' => [],
        ];
    }

    public function test_it_starts_at_page_zero_and_reads_all_pages(): void
    {
        config(['services.publer.api_key' => 'test-key', 'services.publer.workspace_id' => 'ws']);

        $requestedPages = [];

        // Publer pagineert vanaf 0. De verst ingeplande posts staan op pagina 0;
        // wie bij pagina 1 begint mist ze (de oorzaak van de valse "0 posts").
        Http::fake(function ($request) use (&$requestedPages) {
            $data = $request->data();
            $page = (int) ($data['page'] ?? -1);
            $requestedPages[] = $page;
            $this->assertSame('scheduled', $data['state'] ?? null);

            $posts = match ($page) {
                0 => [$this->fakePost('p0', '2026-12-11T07:30:00.000+01:00')],
                1 => [$this->fakePost('p1', '2026-11-27T07:30:00.000+01:00'), $this->fakePost('oud', '2026-10-01T07:30:00.000+02:00', 'published')],
                default => [],
            };

            return Http::response(['posts' => $posts, 'total' => 2], 200);
        });

        $publisher = new PublerPublisher();
        $ids = array_column($publisher->scheduledPostsForAccounts(['acc-a']), 'id');

        $this->assertSame(0, $requestedPages[0], 'De eerste opgevraagde pagina moet 0 zijn.');
        $this->assertContains('p0', $ids, 'Post op pagina 0 moet gevonden worden.');
        $this->assertContains('p1', $ids);
        $this->assertNotContains('oud', $ids);
    }

    public function test_slot_occupancy_sees_posts_beyond_the_first_page(): void
    {
        config(['services.publer.api_key' => 'test-key', 'services.publer.workspace_id' => 'ws']);

        Http::fake(function ($request) {
            $page = (int) ($request->data()['page'] ?? 0);
            $posts = match ($page) {
                0 => [$this->fakePost('a', '2026-12-11T07:30:00.000+01:00')],
                1 => [$this->fakePost('b', '2026-11-27T07:30:00.000+01:00')],
                default => [],
            };
            return Http::response(['posts' => $posts], 200);
        });

        $this->assertCount(2, (new PublerPublisher())->scheduledTimestampsForAccounts(['acc-a']));
    }

    public function test_outage_is_distinguishable_from_empty(): void
    {
        config(['services.publer.api_key' => 'test-key', 'services.publer.workspace_id' => 'ws']);

        Http::fake(fn () => Http::response('error', 500));

        $publisher = new PublerPublisher();
        $this->assertSame([], $publisher->scheduledPostsForAccounts(['acc-a']));
        $this->assertNull($publisher->scheduledPostsForAccountsOrNull(['acc-a']));
    }
}
