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
        $item->changeStatus(ContentStatus::InReview, 'Klaar voor review.');

        Notification::make()
            ->title('Tekst gegenereerd ✓')
            ->body("\"{$item->title}\" staat klaar voor review.")
            ->success()
            ->sendToDatabase(User::first());
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
