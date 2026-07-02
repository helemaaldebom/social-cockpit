<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Jobs\CompressLargeVideosJob;
use App\Jobs\GenerateContentTextJob;
use App\Models\Client;
use App\Models\ContentItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client'          => 'required|string',
            'brief'           => 'required|string',
            'title'           => 'nullable|string',
            'original_text'   => 'nullable|string',
            'channels'        => 'nullable|array',
            'media_urls'      => 'nullable|array',
            'media_urls.*'    => 'nullable|url',
            'external_id'    => 'nullable|string',
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

        // Idempotentie: voorkomt dubbele ContentItems bij retries vanuit de bron.
        if ($externalId = $request->input('external_id')) {
            $existing = ContentItem::where('client_id', $client->id)
                ->where('title', 'LIKE', "%[ext:{$externalId}]%")
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Reeds aangemaakt.',
                    'id'      => $existing->id,
                ], 200);
            }
        }

        $mediaPaths = $this->downloadMedia($request->input('media_urls', []), $client->slug);

        $title = $request->input('title', 'Webhook post');
        if ($externalId = $request->input('external_id')) {
            $title .= " [ext:{$externalId}]";
        }

        $item = ContentItem::create([
            'client_id'     => $client->id,
            'title'         => $title,
            'brief'         => $request->input('brief'),
            'original_text' => $request->input('original_text'),
            'status'        => ContentStatus::Concept->value,
            'media_path'    => $mediaPaths[0] ?? null,
            'media_paths'   => $mediaPaths ?: null,
        ]);

        $channelIds = $request->input('channels')
            ? $client->channels()->whereIn('network', $request->input('channels'))->where('active', true)->pluck('id')
            : $client->channels()->where('active', true)->pluck('id');

        $item->channels()->attach($channelIds);

        // Eerst grote video's comprimeren (>100MB → ~50MB); die job dispatcht
        // daarna zelf GenerateContentTextJob, ook als compressie faalt.
        CompressLargeVideosJob::dispatch($item);

        Log::info('Webhook: content item aangemaakt', [
            'id'          => $item->id,
            'client'      => $client->slug,
            'media_count' => count($mediaPaths),
        ]);

        return response()->json(['message' => 'Content item aangemaakt.', 'id' => $item->id], 201);
    }

    /**
     * Download remote media URLs naar storage/app/public/media en retourneer
     * de relatieve paden. Faalt stilletjes per item (loggen, niet afbreken).
     */
    private function downloadMedia(array $urls, string $clientSlug): array
    {
        $paths = [];
        $disk  = Storage::disk('public');

        foreach ($urls as $url) {
            try {
                $response = Http::timeout(300)->get($url);
                if (! $response->successful()) {
                    Log::warning('Webhook media download mislukt', ['url' => $url, 'status' => $response->status()]);
                    continue;
                }

                $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'bin';
                $filename  = "media/{$clientSlug}/" . Str::ulid() . '.' . strtolower($extension);
                $disk->put($filename, $response->body());

                $paths[] = $filename;
            } catch (\Throwable $e) {
                Log::warning('Webhook media download exception', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }

        return $paths;
    }
}
