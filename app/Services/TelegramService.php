<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $baseUrl;

    public function __construct()
    {
        $token = config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$token}";
    }

    public function sendMessage(string $text, ?int $replyToMessageId = null, ?array $replyMarkup = null): ?int
    {
        $chatId = config('services.telegram.chat_id');

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyToMessageId) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        try {
            $response = Http::post($this->baseUrl . '/sendMessage', $payload);
            return $response->json('result.message_id');
        } catch (\Throwable $e) {
            Log::error('Telegram sendMessage failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function sendPhoto(string $photoPath, string $caption = ''): ?int
    {
        $chatId = config('services.telegram.chat_id');

        try {
            $response = Http::attach('photo', file_get_contents($photoPath), basename($photoPath))
                ->post($this->baseUrl . '/sendPhoto', [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]);
            return $response->json('result.message_id');
        } catch (\Throwable $e) {
            Log::error('Telegram sendPhoto failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function sendVideo(string $videoPath, string $caption = ''): ?int
    {
        $chatId = config('services.telegram.chat_id');

        try {
            $response = Http::attach('video', file_get_contents($videoPath), basename($videoPath))
                ->post($this->baseUrl . '/sendVideo', [
                    'chat_id'    => $chatId,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ]);
            return $response->json('result.message_id');
        } catch (\Throwable $e) {
            Log::error('Telegram sendVideo failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function sendDocument(string $filePath, string $caption = ''): ?int
    {
        $chatId = config('services.telegram.chat_id');

        try {
            $response = Http::attach('document', file_get_contents($filePath), basename($filePath))
                ->post($this->baseUrl . '/sendDocument', [
                    'chat_id'    => $chatId,
                    'caption'    => $caption,
                    'parse_mode' => 'HTML',
                ]);
            return $response->json('result.message_id');
        } catch (\Throwable $e) {
            Log::error('Telegram sendDocument failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Stuurt één mediabestand (foto, video of document) op basis van extensie.
     */
    public function sendMedia(string $absolutePath, string $caption = ''): ?int
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, ['mp4', 'mov', 'avi', 'wmv', 'webm', 'm4v'], true) => $this->sendVideo($absolutePath, $caption),
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)       => $this->sendPhoto($absolutePath, $caption),
            default                                                          => $this->sendDocument($absolutePath, $caption),
        };
    }

    /** Bevestigt een knopdruk (stopt het laad-icoontje, toont evt. een korte melding). */
    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        try {
            Http::post($this->baseUrl . '/answerCallbackQuery', [
                'callback_query_id' => $callbackQueryId,
                'text'              => $text,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram answerCallbackQuery failed', ['error' => $e->getMessage()]);
        }
    }

    /** Haalt de knoppen onder een bericht weg, zodat er niet twee keer geklikt wordt. */
    public function removeButtons(int $messageId): void
    {
        try {
            Http::post($this->baseUrl . '/editMessageReplyMarkup', [
                'chat_id'      => config('services.telegram.chat_id'),
                'message_id'   => $messageId,
                'reply_markup' => json_encode(['inline_keyboard' => []]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram removeButtons failed', ['error' => $e->getMessage()]);
        }
    }

    public function notify(string $message): void
    {
        $this->sendMessage($message);
    }
}
