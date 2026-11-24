<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Jobs\GenerateContentTextJob;
use App\Jobs\SchedulePostToPublerJob;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\OpenAiService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class AutoApproveReviewStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientWithSlotAndChannel(bool $autoApprove): Client
    {
        $client = Client::factory()->create([
            'tone_of_voice' => 'x',
            'active'        => true,
            'auto_approve'  => $autoApprove,
        ]);

        Channel::create([
            'client_id' => $client->id, 'network' => 'linkedin',
            'publer_account_id' => 'acc1', 'name' => 'LI', 'active' => true,
        ]);

        return $client;
    }

    public function test_auto_approve_false_stops_at_in_review_and_does_not_schedule(): void
    {
        OpenAI::fake([CreateResponse::fake()]);
        Bus::fake([SchedulePostToPublerJob::class]);
        User::factory()->create();

        $client = $this->makeClientWithSlotAndChannel(autoApprove: false);

        // Expliciete toekomstige datum: met auto_approve zou dit inplannen.
        $when = Carbon::now('Europe/Amsterdam')->addDays(3)->setTime(9, 0);
        $item = ContentItem::create([
            'client_id' => $client->id, 'title' => 'T', 'brief' => 'b',
            'scheduled_for' => $when,
        ]);

        (new GenerateContentTextJob($item))->handle(app(OpenAiService::class), app(TelegramService::class));

        $this->assertSame(ContentStatus::InReview, $item->fresh()->status);
        Bus::assertNotDispatched(SchedulePostToPublerJob::class);
    }

    public function test_auto_approve_true_still_schedules(): void
    {
        OpenAI::fake([CreateResponse::fake()]);
        Bus::fake([SchedulePostToPublerJob::class]);
        User::factory()->create();

        $client = $this->makeClientWithSlotAndChannel(autoApprove: true);

        $when = Carbon::now('Europe/Amsterdam')->addDays(3)->setTime(9, 0);
        $item = ContentItem::create([
            'client_id' => $client->id, 'title' => 'T', 'brief' => 'b',
            'scheduled_for' => $when,
        ]);

        (new GenerateContentTextJob($item))->handle(app(OpenAiService::class), app(TelegramService::class));

        Bus::assertDispatched(SchedulePostToPublerJob::class);
    }
}
