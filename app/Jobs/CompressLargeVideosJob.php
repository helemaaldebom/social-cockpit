<?php

namespace App\Jobs;

use App\Models\ContentItem;
use App\Services\VideoCompressionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Comprimeert video's > 100 MB in de media van een ContentItem naar ~50 MB,
 * vóór AI-generatie en Publer-scheduling. Zo passen ze binnen Publer's
 * upload-limiet en kunnen ze naar alle netwerken (ook Instagram).
 *
 * Draait als eerste stap in de keten (zie WebhookController): een gefaalde
 * compressie laat het originele bestand staan en blokkeert de rest niet.
 */
class CompressLargeVideosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** ffmpeg op een 234MB-video kan enkele minuten duren. */
    public int $timeout = 1700;

    public function __construct(public readonly ContentItem $contentItem) {}

    public function handle(VideoCompressionService $compressor): void
    {
        $item = $this->contentItem->fresh();
        if (! $item) {
            return;
        }

        $paths   = $item->media_paths ?? [];
        $changed = false;

        foreach ($paths as $index => $relativePath) {
            $newPath = $compressor->compressIfNeeded($relativePath);

            if ($newPath === null) {
                // Compressie mislukt: origineel behouden, alleen loggen.
                Log::warning('CompressLargeVideosJob: compressie mislukt, origineel behouden', [
                    'content_item_id' => $item->id,
                    'path'            => $relativePath,
                ]);
                continue;
            }

            if ($newPath !== $relativePath) {
                $paths[$index] = $newPath;
                $changed = true;
            }
        }

        if ($changed) {
            $item->media_paths = array_values($paths);
            if ($item->media_path && ! in_array($item->media_path, $paths, true)) {
                $item->media_path = $paths[0] ?? null;
            }
            $item->save();
        }

        // Vervolg van de keten: AI-generatie + auto-schedule.
        GenerateContentTextJob::dispatch($item);
    }

    public function failed(\Throwable $exception): void
    {
        // Niet fataal voor de keten: AI-generatie draait gewoon door met het
        // origineel; alleen de Publer-upload kan dan alsnog falen.
        Log::error('CompressLargeVideosJob mislukt — keten gaat door met origineel', [
            'content_item_id' => $this->contentItem->id,
            'error'           => $exception->getMessage(),
        ]);

        if ($item = $this->contentItem->fresh()) {
            GenerateContentTextJob::dispatch($item);
        }
    }
}
