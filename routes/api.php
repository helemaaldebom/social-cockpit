<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1'])->group(function () {
    Route::post('/webhook/content', [WebhookController::class, 'receive'])
        ->middleware(\App\Http\Middleware\ValidateWebhookSignature::class)
        ->name('webhook.content');

    Route::post('/webhook/telegram', [TelegramWebhookController::class, 'handle'])
        ->name('webhook.telegram');
});
