<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Jobs\SendTelegramPreviewJob;
use App\Jobs\SyncPublerPostsJob;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ContentItem;
use App\Services\PublerPublisher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncPublerPostsJobTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithChannels(): Client
    {
        $client = Client::factory()->create(['active' => true]);

        Channel::create([
            'client_id' => $client->id, 'network' => 'facebook',
            'publer_account_id' => 'acc_fb', 'name' => 'ZTS FB', 'active' => true,
        ]);
        Channel::create([
            'client_id' => $client->id, 'network' => 'linkedin',
            'publer_account_id' => 'acc_li', 'name' => 'ZTS LI', 'active' => true,
        ]);

        return $client;
    }

    /** Vervang PublerPublisher door een fake met vaste scheduled posts. */
    private function fakePubler(array $posts): void
    {
        $fake = new class($posts) extends PublerPublisher {
            public function __construct(private array $stub) {}
            public function scheduledTimestampsForAccounts(array $accountIds): array { return []; }
            public function scheduledPostsForAccounts(array $accountIds): array
            {
                return array_values(array_filter(
                    $this->stub,
                    fn ($p) => in_array($p['account_id'], $accountIds, true)
                ));
            }
        };
        $this->app->instance(PublerPublisher::class, $fake);
    }

    private function makePost(string $id, string $account, string $group, Carbon $when, string $text): array
    {
        return [
            'id' => $id, 'account_id' => $account, 'group_id' => $group,
            'text' => $text, 'type' => 'photo',
            'scheduled_at' => $when->copy()->utc(),
            'scheduled_ts' => $when->copy()->utc()->getTimestamp(),
            'media' => [],
        ];
    }

    public function test_imports_grouped_post_as_single_item_with_preview(): void
    {
        Queue::fake();
        $client = $this->clientWithChannels();
        $when = Carbon::now('Europe/Amsterdam')->addDays(5)->setTime(7, 30);

        $this->fakePubler([
            $this->makePost('p_fb', 'acc_fb', 'gr_1', $when, "Mooie trailer afgeleverd! 🚚\n\nTekst."),
            $this->makePost('p_li', 'acc_li', 'gr_1', $when, "Mooie trailer afgeleverd! 🚚\n\nTekst."),
        ]);

        (new SyncPublerPostsJob())->handle(app(PublerPublisher::class));

        $this->assertDatabaseCount('content_items', 1);
        $item = ContentItem::first();
        $this->assertSame(ContentStatus::Ingepland, $item->status);
        $this->assertEqualsCanonicalizing(['p_fb', 'p_li'], $item->publer_post_ids);
        $this->assertSame(2, $item->channels()->count());
        $this->assertStringContainsString('[handmatig via Publer]', $item->title);
        $this->assertSame($when->copy()->utc()->getTimestamp(), $item->scheduled_for->getTimestamp());

        Queue::assertPushed(SendTelegramPreviewJob::class, 1);
    }

    public function test_is_idempotent_and_skips_already_claimed_posts(): void
    {
        Queue::fake();
        $client = $this->clientWithChannels();
        $when = Carbon::now('Europe/Amsterdam')->addDays(5)->setTime(7, 30);

        $existing = ContentItem::create([
            'client_id' => $client->id, 'title' => 'Bestaand',
            'brief' => 'x', 'status' => 'ingepland', 'scheduled_for' => $when,
            'publer_post_ids' => ['p_fb', 'p_li'],
        ]);

        $this->fakePubler([
            $this->makePost('p_fb', 'acc_fb', 'gr_1', $when, 'Tekst'),
            $this->makePost('p_li', 'acc_li', 'gr_1', $when, 'Tekst'),
        ]);

        (new SyncPublerPostsJob())->handle(app(PublerPublisher::class));

        $this->assertDatabaseCount('content_items', 1); // alleen de bestaande
        Queue::assertNotPushed(SendTelegramPreviewJob::class);
    }

    public function test_skips_past_posts(): void
    {
        Queue::fake();
        $this->clientWithChannels();
        $past = Carbon::now('Europe/Amsterdam')->subDays(1)->setTime(7, 30);

        $this->fakePubler([
            $this->makePost('p_old', 'acc_fb', 'gr_old', $past, 'Oud bericht'),
        ]);

        (new SyncPublerPostsJob())->handle(app(PublerPublisher::class));

        $this->assertDatabaseCount('content_items', 0);
        Queue::assertNotPushed(SendTelegramPreviewJob::class);
    }
}
