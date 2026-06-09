<?php

namespace App\Jobs;

use App\Models\ContentItem;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTelegramPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly ContentItem $contentItem) {}

    public function handle(TelegramService $telegram): void
    {
        $item = $this->contentItem->fresh();

        $channels = $item->channels->pluck('name')->join(', ');
        $scheduledFor = $item->scheduled_for
            ? $item->scheduled_for->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i')
            : 'Onbekend';

        $text = "📋 <b>Preview — 24u voor publicatie</b>\n\n"
            . "<b>Klant:</b> {$item->client->name}\n"
            . "<b>Titel:</b> {$item->title}\n"
            . "<b>Kanalen:</b> {$channels}\n"
            . "<b>Gepland op:</b> {$scheduledFor}\n\n"
            . "{$item->generated_text}\n\n"
            . "<i>Antwoord op dit bericht om de tekst aan te passen.</i>";

        if ($item->media_path && file_exists(storage_path('app/public/' . $item->media_path))) {
            $messageId = $telegram->sendPhoto(
                storage_path('app/public/' . $item->media_path),
                $text
            );
        } else {
            $messageId = $telegram->sendMessage($text);
        }

        if ($messageId) {
            $item->telegram_message_id = $messageId;
            $item->save();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTelegramPreviewJob mislukt', [
            'content_item_id' => $this->contentItem->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
