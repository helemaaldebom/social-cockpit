<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Source;
use App\Models\SourceArticle;
use App\Services\OpenAiService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchRssFeedsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    private const WEEKLY_LIMIT = 2;

    public function handle(OpenAiService $openAi): void
    {
        $sources = Source::where('active', true)->with('client')->get();

        foreach ($sources as $source) {
            $this->processSource($source, $openAi);
        }
    }

    private function processSource(Source $source, OpenAiService $openAi): void
    {
        try {
            $response = Http::timeout(10)->get($source->url);

            if (! $response->successful()) {
                Log::warning("RSS feed niet bereikbaar: {$source->url}");
                return;
            }

            $xml = simplexml_load_string($response->body());
            if ($xml === false) {
                Log::warning("Ongeldige RSS feed: {$source->url}");
                return;
            }

            $items = $xml->channel->item ?? $xml->entry ?? [];

            foreach ($items as $feedItem) {
                $url = (string) ($feedItem->link ?? $feedItem->id ?? '');
                $title = (string) ($feedItem->title ?? 'Geen titel');

                if (! $url) {
                    continue;
                }

                // Dedupliceer op URL
                if (SourceArticle::where('external_url', $url)->exists()) {
                    continue;
                }

                // Check weeklimiet
                $weekStart = Carbon::now()->startOfWeek();
                $weekCount = ContentItem::where('client_id', $source->client_id)
                    ->whereNotNull('source_article_id')
                    ->where('created_at', '>=', $weekStart)
                    ->count();

                if ($weekCount >= self::WEEKLY_LIMIT) {
                    break;
                }

                $article = SourceArticle::create([
                    'source_id' => $source->id,
                    'external_url' => $url,
                    'title' => $title,
                    'fetched_at' => now(),
                ]);

                $contentItem = ContentItem::create([
                    'client_id' => $source->client_id,
                    'title' => $title,
                    'brief' => "Genereer een social media bericht op basis van dit artikel: {$url}",
                    'status' => ContentStatus::Concept->value,
                    'source_article_id' => $article->id,
                ]);

                $article->update(['content_item_id' => $contentItem->id]);

                // Koppel actieve kanalen van de klant
                $channelIds = $source->client->channels()
                    ->where('active', true)
                    ->pluck('id');
                $contentItem->channels()->attach($channelIds);

                GenerateContentTextJob::dispatch($contentItem);
            }

            $source->update(['last_fetched_at' => now()]);
        } catch (\Throwable $e) {
            Log::error("FetchRssFeedsJob fout bij source {$source->id}", [
                'url' => $source->url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
