<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Jobs\SchedulePostToPublerJob;
use App\Jobs\SendTelegramReviewJob;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ContentItem;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TelegramApprovalTest extends TestCase
{
    use RefreshDatabase;

    private object $spy;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.chat_id' => '-100', 'services.telegram.webhook_secret' => 'geheim']);

        $this->spy = new class extends TelegramService {
            public array $sent = [];
            public array $answers = [];
            public array $removed = [];
            public function __construct() {}
            public function sendMessage(string $text, ?int $replyToMessageId = null, ?array $replyMarkup = null): ?int
            {
                $this->sent[] = ['text' => $text, 'markup' => $replyMarkup];
                return 900 + count($this->sent);
            }
            public function sendMedia(string $absolutePath, string $caption = ''): ?int { return 1; }
            public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void { $this->answers[] = $text; }
            public function removeButtons(int $messageId): void { $this->removed[] = $messageId; }
        };
        $this->app->instance(TelegramService::class, $this->spy);
    }

    private function makeItem(string $status = 'in_review'): ContentItem
    {
        $client = Client::factory()->create(['active' => true]);
        $ch = Channel::create([
            'client_id' => $client->id, 'network' => 'linkedin',
            'publer_account_id' => 'acc-li', 'name' => 'LI', 'active' => true,
        ]);
        $item = ContentItem::create([
            'client_id' => $client->id, 'title' => 'T', 'brief' => 'b',
            'generated_text' => 'Tekst <b>met</b> & tekens', 'original_text' => 'bron',
            'source_note' => 'filmpje wilde niet uploaden',
            'scheduled_for' => Carbon::now()->addDays(3),
            'telegram_message_id' => 555,
        ]);
        $item->status = ContentStatus::from($status);
        $item->save();
        $item->channels()->attach($ch->id);

        return $item;
    }

    private function press(string $data, int $messageId = 555, string $secret = 'geheim', string $chat = '-100')
    {
        return $this->postJson('/api/webhook/telegram', [
            'callback_query' => [
                'id' => 'cb1', 'data' => $data, 'from' => ['first_name' => 'Bas'],
                'message' => ['message_id' => $messageId, 'chat' => ['id' => $chat]],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    public function test_approve_button_approves_and_schedules(): void
    {
        Bus::fake([SchedulePostToPublerJob::class]);
        $item = $this->makeItem();

        $this->press("approve:{$item->id}")->assertOk();

        $this->assertSame(ContentStatus::Goedgekeurd, $item->fresh()->status);
        Bus::assertDispatched(SchedulePostToPublerJob::class, fn ($job) => $job->publerAccountIds === ['acc-li']);
        $this->assertContains(555, $this->spy->removed);
        $this->assertStringContainsString('goedgekeurd', $this->spy->sent[0]['text']);
    }

    public function test_reject_button_never_schedules(): void
    {
        Bus::fake([SchedulePostToPublerJob::class]);
        $item = $this->makeItem();

        $this->press("reject:{$item->id}")->assertOk();

        $this->assertSame(ContentStatus::Mislukt, $item->fresh()->status);
        Bus::assertNotDispatched(SchedulePostToPublerJob::class);
    }

    public function test_wrong_secret_or_chat_is_ignored(): void
    {
        Bus::fake([SchedulePostToPublerJob::class]);
        $item = $this->makeItem();

        $this->press("approve:{$item->id}", 555, 'fout')->assertStatus(403);
        $this->press("approve:{$item->id}", 555, 'geheim', '-999')->assertOk();

        $this->assertSame(ContentStatus::InReview, $item->fresh()->status);
        Bus::assertNotDispatched(SchedulePostToPublerJob::class);
    }

    public function test_second_press_and_stale_message_do_nothing(): void
    {
        Bus::fake([SchedulePostToPublerJob::class]);
        $item = $this->makeItem();

        // Knop onder een ouder bericht (tekst is daarna aangepast).
        $this->press("approve:{$item->id}", 111)->assertOk();
        $this->assertSame(ContentStatus::InReview, $item->fresh()->status);

        $this->press("approve:{$item->id}")->assertOk();
        $this->press("approve:{$item->id}")->assertOk(); // dubbelklik
        Bus::assertDispatchedTimes(SchedulePostToPublerJob::class, 1);
    }

    public function test_review_message_has_buttons_and_escapes_text(): void
    {
        $item = $this->makeItem();

        (new SendTelegramReviewJob($item->id))->handle(app(TelegramService::class));

        $msg = $this->spy->sent[0];
        $this->assertSame("approve:{$item->id}", $msg['markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertStringContainsString('Tekst &lt;b&gt;met&lt;/b&gt; &amp; tekens', $msg['text']);
        $this->assertStringContainsString('filmpje wilde niet uploaden', $msg['text']);
        $this->assertSame(901, $item->fresh()->telegram_message_id);
    }

    public function test_reply_with_tekst_prefix_replaces_text_verbatim_and_reoffers(): void
    {
        $item = $this->makeItem();

        $this->postJson('/api/webhook/telegram', [
            'message' => [
                'chat' => ['id' => '-100'], 'text' => "tekst: Precies deze tekst.\nTweede regel.",
                'reply_to_message' => ['message_id' => 555],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'geheim'])->assertOk();

        $fresh = $item->fresh();
        $this->assertSame("Precies deze tekst.\nTweede regel.", $fresh->generated_text);
        $this->assertSame(ContentStatus::InReview, $fresh->status);
        $this->assertNotNull($this->spy->sent[0]['markup']);
        $this->assertNotSame(555, $fresh->telegram_message_id);
    }
}
