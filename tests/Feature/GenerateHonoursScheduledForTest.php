<?php

namespace Tests\Feature;

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

class GenerateHonoursScheduledForTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_form_chosen_scheduled_for_instead_of_next_free_slot(): void
    {
        OpenAI::fake([CreateResponse::fake()]);
        Bus::fake([SchedulePostToPublerJob::class]);
        User::factory()->create();

        $client = Client::factory()->create(['tone_of_voice' => 'x', 'active' => true]);
        Channel::create([
            'client_id' => $client->id, 'network' => 'linkedin',
            'publer_account_id' => 'acc1', 'name' => 'LI', 'active' => true,
        ]);

        // Klant heeft GEEN actief slot: zonder de gekozen datum zou er niets
        // ingepland worden. Met de datum uit het formulier wél.
        $when = Carbon::now('Europe/Amsterdam')->addDays(3)->setTime(9, 0);

        $item = ContentItem::create([
            'client_id' => $client->id, 'title' => 'T', 'brief' => 'b',
            'scheduled_for' => $when,
        ]);

        (new GenerateContentTextJob($item))->handle(app(OpenAiService::class), app(TelegramService::class));

        Bus::assertDispatched(SchedulePostToPublerJob::class, function ($job) use ($when) {
            return Carbon::parse($job->scheduledFor)->equalTo($when);
        });
    }
}
