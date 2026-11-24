<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Jobs\GenerateContentTextJob;
use App\Models\Client;
use App\Models\ContentItem;
use App\Services\OpenAiService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private TelegramService $telegram,
        private OpenAiService $openAi,
        private \App\Services\ApprovalService $approval
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // Alleen Telegram zelf kent dit geheim (ingesteld via setWebhook). Zonder
        // deze check kan iedereen die de URL kent een goedkeuring vervalsen.
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret !== '' && ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false], 403);
        }

        $update = $request->all();
        $allowedChatId = (string) config('services.telegram.chat_id');

        if ($callback = $update['callback_query'] ?? null) {
            if ((string) ($callback['message']['chat']['id'] ?? '') === $allowedChatId) {
                $this->handleCallback($callback);
            }
            return response()->json(['ok' => true]);
        }

        $message = $update['message'] ?? null;

        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) ($message['chat']['id'] ?? '');

        if ($chatId !== $allowedChatId) {
            return response()->json(['ok' => true]);
        }

        $text = $message['text'] ?? '';
        $replyTo = $message['reply_to_message'] ?? null;

        if ($replyTo) {
            $this->handleReview($message, $replyTo, $text);
        } else {
            $this->handleCreate($message, $text);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Knop Goedkeuren / Afwijzen onder een review-bericht.
     */
    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $messageId  = (int) ($callback['message']['message_id'] ?? 0);
        $who        = trim((string) ($callback['from']['first_name'] ?? '')) ?: 'Telegram';

        if (! preg_match('/^(approve|reject):(\d+)$/', (string) ($callback['data'] ?? ''), $m)) {
            $this->telegram->answerCallbackQuery($callbackId);
            return;
        }

        [$action, $id] = [$m[1], (int) $m[2]];
        $item = ContentItem::with('client', 'channels')->find($id);

        if (! $item) {
            $this->telegram->answerCallbackQuery($callbackId, 'Post niet gevonden.');
            return;
        }

        // Alleen het meest recente review-bericht telt. Een knop onder een
        // ouder bericht hoort bij een tekst die inmiddels is aangepast.
        if ($item->telegram_message_id && $messageId && (int) $item->telegram_message_id !== $messageId) {
            $this->telegram->answerCallbackQuery($callbackId, 'Dit is een oudere versie — gebruik het laatste bericht.');
            $this->telegram->removeButtons($messageId);
            return;
        }

        if ($item->status !== ContentStatus::InReview) {
            $this->telegram->answerCallbackQuery($callbackId, 'Al afgehandeld: ' . $item->status->label());
            if ($messageId) {
                $this->telegram->removeButtons($messageId);
            }
            return;
        }

        try {
            if ($action === 'approve') {
                $slot = $this->approval->approve($item, "Telegram ({$who})");
                $this->telegram->answerCallbackQuery($callbackId, 'Goedgekeurd');
                $this->telegram->sendMessage(
                    "✅ <b>#{$item->id} goedgekeurd</b> door " . e($who) . ".\n"
                    . ($slot
                        ? 'Wordt ingepland op <b>' . $slot->format('d-m-Y H:i') . '</b>. Je krijgt een melding als dat niet lukt.'
                        : 'Er is nu geen vrij slot; hij wordt ingepland zodra er een vrijkomt.'),
                    $messageId ?: null
                );
            } else {
                $this->approval->reject($item, "Telegram ({$who})");
                $this->telegram->answerCallbackQuery($callbackId, 'Afgewezen');
                $this->telegram->sendMessage("❌ <b>#{$item->id} afgewezen</b> door " . e($who) . '. Wordt niet geplaatst.', $messageId ?: null);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram goedkeuring mislukt', ['content_item_id' => $item->id, 'error' => $e->getMessage()]);
            $this->telegram->answerCallbackQuery($callbackId, 'Er ging iets mis.');
            $this->telegram->sendMessage("⚠️ Goedkeuren/afwijzen van #{$item->id} is mislukt: " . e($e->getMessage()));
            return;
        }

        if ($messageId) {
            $this->telegram->removeButtons($messageId);
        }
    }

    private function handleReview(array $message, array $replyTo, string $instruction): void
    {
        $replyMessageId = $replyTo['message_id'] ?? null;

        if (! $replyMessageId) {
            return;
        }

        $item = ContentItem::where('telegram_message_id', $replyMessageId)->first();

        if (! $item) {
            $this->telegram->sendMessage('Geen content item gevonden voor dit bericht.');
            return;
        }

        try {
            // "tekst: ..." vervangt de tekst letterlijk, zonder AI.
            if (preg_match('/^\s*tekst\s*:\s*(.+)$/isu', $instruction, $m)) {
                $refinedText = trim($m[1]);
            } else {
                $refinedText = $this->openAi->refineText($item, $instruction);
            }

            $item->generated_text = $refinedText;
            $item->save();

            // Nog niet goedgekeurd: opnieuw ter goedkeuring aanbieden met knoppen.
            if ($item->status === ContentStatus::InReview) {
                $this->telegram->removeButtons((int) $replyMessageId);
                \App\Jobs\SendTelegramReviewJob::dispatchSync($item->id, false);
                return;
            }

            if ($item->publer_post_id) {
                dispatch(new \App\Jobs\UpdatePublerPostJob($item));
            }

            $channels = $item->channels->pluck('name')->join(', ');
            $preview = "✏️ <b>Bijgewerkte tekst voor " . e($item->client->name) . "</b>\n\n"
                . e($refinedText) . "\n\n"
                . "<b>Kanalen:</b> " . e($channels) . "\n"
                . "<i>Antwoord op dit bericht voor verdere aanpassingen.</i>";

            $newMessageId = $this->telegram->sendMessage($preview);
            if ($newMessageId) {
                $item->telegram_message_id = $newMessageId;
                $item->save();
            }
        } catch (\Throwable $e) {
            Log::error('Telegram review mislukt', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage('Er is een fout opgetreden bij het aanpassen van de tekst.');
        }
    }

    private function handleCreate(array $message, string $text): void
    {
        // Zoek klantnaam in de berichttekst
        $clients = Client::where('active', true)->get();
        $matchedClient = null;

        foreach ($clients as $client) {
            if (stripos($text, $client->name) !== false || stripos($text, $client->slug) !== false) {
                $matchedClient = $client;
                break;
            }
        }

        if (! $matchedClient) {
            $clientList = $clients->pluck('name')->join(', ');
            $this->telegram->sendMessage(
                "Geen klant herkend in je bericht. Beschikbare klanten: {$clientList}"
            );
            return;
        }

        $item = ContentItem::create([
            'client_id' => $matchedClient->id,
            'title' => 'Telegram post',
            'brief' => $text,
            'status' => ContentStatus::Concept->value,
        ]);

        // Media meesturen indien aanwezig
        if (isset($message['photo'])) {
            // Telegram stuurt meerdere formaten, neem de grootste
            $photo = end($message['photo']);
            $fileId = $photo['file_id'] ?? null;
            if ($fileId) {
                $item->media_path = "telegram/{$fileId}";
                $item->save();
            }
        }

        $channelIds = $matchedClient->channels()->where('active', true)->pluck('id');
        $item->channels()->attach($channelIds);

        GenerateContentTextJob::dispatch($item);

        $this->telegram->sendMessage(
            "✅ Content item aangemaakt voor <b>{$matchedClient->name}</b>.\n"
            . "Ik genereer de tekst, je krijgt een preview zodra die klaar is."
        );
    }
}
