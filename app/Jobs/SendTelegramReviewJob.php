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

/**
 * Stuurt een post ter goedkeuring naar Telegram: eerst de media, daarna de
 * tekst met de knoppen Goedkeuren / Afwijzen. Een antwoord op dat bericht
 * past de tekst aan (zie TelegramWebhookController).
 */
class SendTelegramReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public readonly int $contentItemId, public readonly bool $withMedia = true) {}

    public function handle(TelegramService $telegram): void
    {
        $item = ContentItem::with('client', 'channels')->find($this->contentItemId);

        if (! $item || $item->status !== ContentStatus::InReview) {
            return;
        }

        if ($this->withMedia) {
            foreach ($item->allMediaPaths() as $relativePath) {
                $absolute = storage_path('app/public/' . $relativePath);
                if (file_exists($absolute)) {
                    $telegram->sendMedia($absolute);
                }
            }
        }

        $messageId = $telegram->sendMessage(self::buildText($item), null, self::buttons($item));

        if (! $messageId) {
            Log::warning('Telegram review-bericht niet verstuurd', ['content_item_id' => $item->id]);
            return;
        }

        $item->telegram_message_id = $messageId;
        $item->save();
    }

    public static function buttons(ContentItem $item): array
    {
        return ['inline_keyboard' => [[
            ['text' => '✅ Goedkeuren', 'callback_data' => "approve:{$item->id}"],
            ['text' => '❌ Afwijzen', 'callback_data' => "reject:{$item->id}"],
        ]]];
    }

    public static function buildText(ContentItem $item): string
    {
        $channels = $item->channels->pluck('name')->join(', ') ?: '—';
        $when = ($item->scheduled_for && $item->scheduled_for->isFuture())
            ? $item->scheduled_for->copy()->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i')
            : 'eerstvolgende vrije slot';
        $mediaCount = count($item->allMediaPaths());

        // Telegram-bericht max 4096 tekens: kap de onderdelen ruim daaronder af.
        $text  = e(mb_strimwidth((string) $item->generated_text, 0, 2600, '…'));
        $lines = [
            "📝 <b>Ter goedkeuring — #{$item->id}</b>",
            '<b>Klant:</b> ' . e($item->client?->name ?? '—'),
            '<b>Kanalen:</b> ' . e($channels),
            "<b>Gepland:</b> {$when}",
            "<b>Media:</b> {$mediaCount}",
            '',
            '<b>Tekst die geplaatst wordt:</b>',
            $text,
        ];

        if (filled($item->original_text)) {
            $lines[] = '';
            $lines[] = '<b>Ingezonden tekst:</b>';
            $lines[] = '<i>' . e(mb_strimwidth($item->original_text, 0, 600, '…')) . '</i>';
        }

        if (filled($item->source_note)) {
            $lines[] = '';
            $lines[] = '⚠️ <b>Opmerking van inzender (wordt niet geplaatst):</b>';
            $lines[] = e(mb_strimwidth($item->source_note, 0, 400, '…'));
        }

        $lines[] = '';
        $lines[] = '<i>Antwoord op dit bericht om de tekst te laten aanpassen. Begin met "tekst:" om hem letterlijk te vervangen.</i>';

        return implode("\n", $lines);
    }
}
