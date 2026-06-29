<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\OpenAiService;
use App\Services\TelegramService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateContentTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public readonly ContentItem $contentItem) {}

    public function handle(OpenAiService $openAi, TelegramService $telegram): void
    {
        $item = $this->contentItem->fresh();

        // Zet terug naar concept zodat de state machine door kan
        if (! in_array($item->status, [ContentStatus::Concept, ContentStatus::Mislukt])) {
            $item->status = ContentStatus::Concept;
            $item->save();
        }

        $text = $openAi->generateText($item);
        $item->generated_text = $text;
        $item->save();

        $item->changeStatus(ContentStatus::Gegenereerd, 'Tekst gegenereerd via OpenAI.');

        // Probeer direct auto-in te plannen op het eerstvolgende vrije slot.
        // Lukt dat niet (geen slots, geen Publer-accounts, geen vrij moment),
        // dan valt het terug op de oude flow: status InReview voor handmatige
        // goedkeuring in Filament.
        if ($this->autoScheduleIfPossible($item)) {
            return;
        }

        $item->changeStatus(ContentStatus::InReview, 'Klaar voor review (geen automatisch slot gevonden).');

        Notification::make()
            ->title('Tekst gegenereerd ✓')
            ->body("\"{$item->title}\" staat klaar voor review.")
            ->success()
            ->sendToDatabase(User::first());
    }

    /**
     * Plant het item direct in op het eerstvolgende vrije slot van de klant.
     * Retourneert true als het is gelukt.
     */
    private function autoScheduleIfPossible(ContentItem $item): bool
    {
        $client = $item->client;
        if (! $client) {
            return false;
        }

        $publerAccountIds = $client->channels()
            ->where('active', true)
            ->whereNotNull('publer_account_id')
            ->pluck('publer_account_id')
            ->toArray();

        if (empty($publerAccountIds)) {
            Log::info('Auto-schedule overgeslagen: geen Publer-accounts', ['content_item_id' => $item->id]);
            return false;
        }

        $slot = $client->nextFreeSlot();
        if (! $slot) {
            Log::info('Auto-schedule overgeslagen: geen vrij slot gevonden', ['content_item_id' => $item->id]);
            return false;
        }

        $item->changeStatus(
            ContentStatus::Goedgekeurd,
            'Automatisch goedgekeurd voor inplanning op ' . $slot->format('d-m-Y H:i') . '.'
        );

        SchedulePostToPublerJob::dispatch(
            $item,
            $publerAccountIds,
            $slot->toIso8601String()
        );

        Notification::make()
            ->title('Tekst gegenereerd + ingepland ✓')
            ->body("\"{$item->title}\" wordt ingepland op " . $slot->format('d-m-Y H:i') . '.')
            ->success()
            ->sendToDatabase(User::first());

        Log::info('Auto-schedule gedispatcht', [
            'content_item_id' => $item->id,
            'scheduled_for'   => $slot->toIso8601String(),
        ]);

        return true;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateContentTextJob mislukt', [
            'content_item_id' => $this->contentItem->id,
            'error' => $exception->getMessage(),
        ]);

        try {
            $item = $this->contentItem->fresh();
            if ($item) {
                $item->changeStatus(ContentStatus::Mislukt, 'OpenAI-generatie mislukt: ' . $exception->getMessage());
            }
        } catch (\Throwable) {}

        app(TelegramService::class)->notify(
            "❌ OpenAI-generatie mislukt voor content item #{$this->contentItem->id}.\n{$exception->getMessage()}"
        );
    }
}
