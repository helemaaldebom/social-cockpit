<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Jobs\SendTelegramPreviewJob;
use App\Models\Client;
use App\Models\ContentItem;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramPreviewOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_preview_jobs_send_only_one_message(): void
    {
        $spy = new class extends TelegramService {
            public int $count = 0;
            public function __construct() {}
            public function sendMessage(string $text, ?int $replyToMessageId = null, ?array $replyMarkup = null): ?int
            {
                return 100 + (++$this->count);
            }
        };
        $this->app->instance(TelegramService::class, $spy);

        $client = Client::factory()->create();
        $item = ContentItem::create([
            'client_id' => $client->id, 'title' => 'T', 'brief' => 'b', 'generated_text' => 'tekst',
            'scheduled_for' => Carbon::now()->addHours(20),
        ]);
        $item->status = ContentStatus::Ingepland;
        $item->save();

        // Vier keer (her)ingepland = vier jobs in de queue.
        for ($i = 0; $i < 4; $i++) {
            (new SendTelegramPreviewJob($item))->handle($spy);
        }

        $this->assertSame(1, $spy->count);
    }
}
