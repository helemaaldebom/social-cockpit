<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Filament\Resources\ContentItemResource;
use App\Jobs\GenerateContentTextJob;
use App\Jobs\ProcessAutoSchedulerJob;
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

class ApprovalOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(array $attrs = []): ContentItem
    {
        $client = Client::factory()->create(['tone_of_voice' => 'x', 'active' => true]);
        $li = Channel::create([
            'client_id' => $client->id, 'network' => 'linkedin',
            'publer_account_id' => 'acc-li', 'name' => 'LI', 'active' => true,
        ]);
        Channel::create([
            'client_id' => $client->id, 'network' => 'facebook',
            'publer_account_id' => 'acc-fb', 'name' => 'FB', 'active' => true,
        ]);

        $item = ContentItem::create(array_merge([
            'client_id' => $client->id, 'title' => 'T', 'brief' => 'b',
        ], $attrs));
        $item->channels()->attach($li->id); // alleen LinkedIn gekozen

        return $item;
    }

    public function test_generation_never_schedules_and_always_stops_at_in_review(): void
    {
        OpenAI::fake([CreateResponse::fake()]);
        Bus::fake([SchedulePostToPublerJob::class, \App\Jobs\SendTelegramReviewJob::class]);
        User::factory()->create();

        // Zelfs mét expliciete toekomstige datum mag er niets ingepland worden.
        $item = $this->makeItem(['scheduled_for' => Carbon::now()->addDays(3)]);

        (new GenerateContentTextJob($item))->handle(app(OpenAiService::class), app(TelegramService::class));

        $this->assertSame(ContentStatus::InReview, $item->fresh()->status);
        Bus::assertNotDispatched(SchedulePostToPublerJob::class);
        Bus::assertDispatched(\App\Jobs\SendTelegramReviewJob::class);
    }

    public function test_scheduler_ignores_items_that_are_not_approved(): void
    {
        Bus::fake([SchedulePostToPublerJob::class]);

        $item = $this->makeItem(['scheduled_for' => Carbon::now()->addDays(3), 'generated_text' => 'tekst']);
        $item->status = ContentStatus::InReview;
        $item->save();

        (new ProcessAutoSchedulerJob())->handle();

        Bus::assertNotDispatched(SchedulePostToPublerJob::class);
    }

    public function test_approval_schedules_on_chosen_time_for_the_items_own_channels_only(): void
    {
        Bus::fake([SchedulePostToPublerJob::class]);

        $when = Carbon::now('Europe/Amsterdam')->addDays(3)->setTime(9, 0);
        $item = $this->makeItem(['scheduled_for' => $when, 'generated_text' => 'tekst']);
        $item->status = ContentStatus::InReview;
        $item->save();

        ContentItemResource::approveRecord($item, notify: false);

        $this->assertSame(ContentStatus::Goedgekeurd, $item->fresh()->status);
        Bus::assertDispatched(SchedulePostToPublerJob::class, function ($job) use ($when) {
            return $job->publerAccountIds === ['acc-li']
                && Carbon::parse($job->scheduledFor)->equalTo($when);
        });
    }
}
