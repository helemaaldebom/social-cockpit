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

    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'wmv', 'webm', 'm4v'];
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(public readonly ContentItem $contentItem) {}

    public function handle(TelegramService $telegram): void
    {
        $item = $this->contentItem->fresh();

        $channels = $item->channels->pluck('name')->join(', ');
        $scheduledFor = $item->scheduled_for
            ? $item->scheduled_for->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i')
            : 'Onbekend';

        $caption = "📋 <b>Preview — 24u voor publicatie</b>\n\n"
            . "<b>Klant:</b> {$item->client->name}\n"
            . "<b>Titel:</b> {$item->title}\n"
            . "<b>Kanalen:</b> {$channels}\n"
            . "<b>Gepland op:</b> {$scheduledFor}\n\n"
            . "{$item->generated_text}\n\n"
            . "<i>Antwoord op dit bericht om de tekst aan te passen. Geen actie nodig als de post goed is.</i>";

        $mediaPaths = $item->allMediaPaths();
        $messageId  = null;

        if (empty($mediaPaths)) {
            $messageId = $telegram->sendMessage($caption);
        } else {
            // Eerste medium krijgt de caption (Telegram toont maar één caption per bericht).
            $first = array_shift($mediaPaths);
            $messageId = $this->sendOne($telegram, $first, $caption);

            // Eventuele extra media: zonder caption, als losse berichten.
            foreach ($mediaPaths as $extra) {
                $this->sendOne($telegram, $extra, '');
            }
        }

        if ($messageId) {
            $item->telegram_message_id = $messageId;
            $item->save();
        }
    }

    private function sendOne(TelegramService $telegram, string $relativePath, string $caption): ?int
    {
        $absolute = storage_path('app/public/' . $relativePath);

        if (! file_exists($absolute)) {
            Log::warning('Telegram preview: media bestand niet gevonden', ['path' => $absolute]);
            return $telegram->sendMessage($caption ?: "(media ontbreekt: {$relativePath})");
        }

        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
            return $telegram->sendVideo($absolute, $caption);
        }

        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return $telegram->sendPhoto($absolute, $caption);
        }

        // Onbekend formaat (bv. PDF): stuur als document.
        return $telegram->sendDocument($absolute, $caption);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTelegramPreviewJob mislukt', [
            'content_item_id' => $this->contentItem->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
