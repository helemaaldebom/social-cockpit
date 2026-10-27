<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Jobs\ResolvePublerPostIdsJob;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ContentItem;
use App\Services\PublerPublisher;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveVerifiesPublerTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(array $accountIds): ContentItem
    {
        $client = Client::factory()->create();
        foreach ($accountIds as $acc) {
            Channel::create([
                'client_id' => $client->id, 'network' => 'linkedin',
                'publer_account_id' => $acc, 'name' => 'ch-' . $acc, 'active' => true,
            ]);
        }

        return ContentItem::create([
            'client_id' => $client->id, 'title' => 'T', 'brief' => 'b',
            'status' => 'ingepland',
            'scheduled_for' => Carbon::now('Europe/Amsterdam')->addDays(2)->setTime(7, 30),
            'publer_post_id' => 'tmp-job-id',
        ]);
    }

    private function fakePublisher(array $matchReturn): void
    {
        $fake = new class($matchReturn) extends PublerPublisher {
            public function __construct(private array $ret) {}
            public function matchScheduledPostIds(array $accountIds, \Carbon\CarbonInterface $scheduledFor, int $toleranceSec = 120): array
            {
                return $this->ret;
            }
        };
        $this->app->instance(PublerPublisher::class, $fake);
    }

    private function spyTelegram(): object
    {
        $spy = new class extends TelegramService {
            public array $sent = [];
            public function __construct() {}
            public function notify(string $message): void { $this->sent[] = $message; }
        };
        $this->app->instance(TelegramService::class, $spy);
        return $spy;
    }

    private function runResolve(ContentItem $item, int $attempt): void
    {
        $accs = $item->client->channels->pluck('publer_account_id')->all();
        (new ResolvePublerPostIdsJob($item->id, $accs, $item->scheduled_for->toIso8601String(), $attempt))
            ->handle(app(PublerPublisher::class), app(TelegramService::class));
    }

    public function test_zero_posts_marks_item_failed_and_alerts(): void
    {
        $this->fakePublisher([]);
        $tg = $this->spyTelegram();
        $item = $this->makeItem(['a', 'b', 'c']);

        $this->runResolve($item, 6); // laatste poging

        $item->refresh();
        $this->assertSame(ContentStatus::Mislukt, $item->status);
        $this->assertNull($item->publer_post_ids);
        $this->assertCount(1, $tg->sent);
        $this->assertStringContainsString('0 van 3', $tg->sent[0]);
    }

    public function test_partial_keeps_found_ids_and_warns(): void
    {
        $this->fakePublisher(['p1', 'p2']);
        $tg = $this->spyTelegram();
        $item = $this->makeItem(['a', 'b', 'c']);

        $this->runResolve($item, 6);

        $item->refresh();
        $this->assertSame(ContentStatus::Ingepland, $item->status);
        $this->assertSame(['p1', 'p2'], $item->publer_post_ids);
        $this->assertCount(1, $tg->sent);
        $this->assertStringContainsString('2 van 3', $tg->sent[0]);
    }

    public function test_full_success_sets_ids_without_alert(): void
    {
        $this->fakePublisher(['p1', 'p2']);
        $tg = $this->spyTelegram();
        $item = $this->makeItem(['a', 'b']);

        $this->runResolve($item, 1);

        $item->refresh();
        $this->assertSame(ContentStatus::Ingepland, $item->status);
        $this->assertSame(['p1', 'p2'], $item->publer_post_ids);
        $this->assertCount(0, $tg->sent);
    }
}
