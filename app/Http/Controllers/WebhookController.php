<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Jobs\GenerateContentTextJob;
use App\Models\Client;
use App\Models\ContentItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client' => 'required|string',
            'brief' => 'required|string',
            'title' => 'nullable|string',
            'channels' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $client = Client::where('slug', $request->input('client'))
            ->orWhere('name', $request->input('client'))
            ->where('active', true)
            ->first();

        if (! $client) {
            Log::warning('Webhook: onbekende klant', ['client' => $request->input('client')]);
            return response()->json(['error' => 'Klant niet gevonden.'], 404);
        }

        $item = ContentItem::create([
            'client_id' => $client->id,
            'title' => $request->input('title', 'Webhook post'),
            'brief' => $request->input('brief'),
            'status' => ContentStatus::Concept->value,
        ]);

        $channelIds = $request->input('channels')
            ? $client->channels()->whereIn('network', $request->input('channels'))->where('active', true)->pluck('id')
            : $client->channels()->where('active', true)->pluck('id');

        $item->channels()->attach($channelIds);

        GenerateContentTextJob::dispatch($item);

        Log::info('Webhook: content item aangemaakt', ['id' => $item->id, 'client' => $client->slug]);

        return response()->json(['message' => 'Content item aangemaakt.', 'id' => $item->id], 201);
    }
}
