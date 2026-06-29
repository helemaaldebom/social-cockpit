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

    public function sendMessage(string $text, ?int $replyToMessageId = null): ?int
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

    public function notify(string $message): void
    {
        $this->sendMessage($message);
    }
}
