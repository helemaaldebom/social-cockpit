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
        private OpenAiService $openAi
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();
        $message = $update['message'] ?? null;

        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $allowedChatId = (string) config('services.telegram.chat_id');

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
            $refinedText = $this->openAi->refineText($item, $instruction);
            $item->generated_text = $refinedText;
            $item->save();

            if ($item->publer_post_id) {
                dispatch(new \App\Jobs\UpdatePublerPostJob($item));
            }

            $channels = $item->channels->pluck('name')->join(', ');
            $preview = "✏️ <b>Bijgewerkte tekst voor {$item->client->name}</b>\n\n"
                . "{$refinedText}\n\n"
                . "<b>Kanalen:</b> {$channels}\n"
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
