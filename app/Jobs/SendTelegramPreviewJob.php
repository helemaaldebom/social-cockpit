<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
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

        // GUARDS — deze job kan dagen in de delayed-queue staan; controleer
        // dat het item op vuurmoment nog bestaat en nog relevant is. Zonder
        // deze checks stuurden we previews voor allang verwijderde posts.
        if (! $item || $item->trashed()) {
            Log::info('Telegram preview overgeslagen: item verwijderd', [
                'content_item_id' => $this->contentItem->id,
            ]);
            return;
        }

        if ($item->status !== ContentStatus::Ingepland) {
            Log::info('Telegram preview overgeslagen: item niet meer ingepland', [
                'content_item_id' => $item->id,
                'status'          => $item->status->value,
            ]);
            return;
        }

        if (! $item->scheduled_for || $item->scheduled_for->isPast()) {
            Log::info('Telegram preview overgeslagen: publicatiemoment al voorbij', [
                'content_item_id' => $item->id,
            ]);
            return;
        }

        // Item herpland naar later? Dan vuurt deze (oude) job te vroeg —
        // opnieuw inplannen op het juiste moment en nu niets sturen.
        if (now()->lt($item->scheduled_for->copy()->subHours(23))) {
            self::dispatch($item)->delay($item->scheduled_for->copy()->subHours(22));
            Log::info('Telegram preview her-ingepland (item was verzet)', [
                'content_item_id' => $item->id,
                'nieuw_moment'    => $item->scheduled_for->copy()->subHours(22)->toIso8601String(),
            ]);
            return;
        }

        $channels = $item->channels->pluck('name')->join(', ');
        $scheduledFor = $item->scheduled_for->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i');

        $caption = "📋 <b>Preview — 22u voor publicatie</b>\n\n"
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

        // Vangnet: media-verzending kan stil falen (bv. video boven Telegram's
        // limiet). De preview MOET altijd aankomen — val terug op tekst-only.
        if (! $messageId) {
            Log::warning('Telegram preview: media-verzending faalde, val terug op tekst', [
                'content_item_id' => $item->id,
            ]);
            $messageId = $telegram->sendMessage(
                $caption . "\n\n<i>(Media kon niet als bijlage mee — bekijk de media in Publer.)</i>"
            );
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
