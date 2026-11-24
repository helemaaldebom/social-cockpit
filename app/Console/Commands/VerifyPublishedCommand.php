<?php

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Services\PublerPublisher;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Controleert ná het geplande tijdstip of een post écht is gepubliceerd.
 * Voorheen bleef een item eeuwig op "ingepland" staan en merkte niemand het
 * als Publer een post niet (of maar op een deel van de netwerken) plaatste.
 */
class VerifyPublishedCommand extends Command
{
    protected $signature = 'social:verify-published';
    protected $description = 'Controleer of ingeplande posts daadwerkelijk zijn gepubliceerd';

    public function handle(PublerPublisher $publer, TelegramService $telegram): int
    {
        $items = ContentItem::with('client')
            ->where('status', ContentStatus::Ingepland->value)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<', now()->subMinutes(20))
            ->where('scheduled_for', '>', now()->subDays(3))
            ->get();

        foreach ($items as $item) {
            $ids = $item->publer_post_ids ?: array_filter([$item->publer_post_id]);
            if (empty($ids)) {
                continue;
            }

            $published = 0;
            $problems  = [];
            $unknown   = false;

            foreach ($ids as $id) {
                $status = $publer->getPostStatus((string) $id);

                if ($status === false) {
                    $unknown = true;
                    break;
                }
                if ($status === null) {
                    $problems[] = "{$id}: bestaat niet (meer) in Publer";
                    continue;
                }
                if ($status['state'] === 'published') {
                    $published++;
                    continue;
                }
                if (! empty($status['error']) || str_contains($status['state'], 'fail')) {
                    $problems[] = "{$id}: {$status['state']} " . (is_string($status['error']) ? $status['error'] : json_encode($status['error']));
                    continue;
                }
                // Nog 'scheduled' terwijl de tijd verstreken is: nog even wachten,
                // maar na 2 uur is dat zelf een probleem.
                if ($item->scheduled_for->lt(now()->subHours(2))) {
                    $problems[] = "{$id}: staat nog op '{$status['state']}' terwijl de publicatietijd voorbij is";
                } else {
                    $unknown = true;
                }
            }

            if ($unknown) {
                continue; // volgende run opnieuw
            }

            $when = $item->scheduled_for->copy()->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i');

            if (empty($problems)) {
                $item->changeStatus(ContentStatus::Geplaatst, "Publicatie bevestigd door Publer ({$published} netwerk(en)).");
                continue;
            }

            Log::warning('VerifyPublished: probleem bij publicatie', ['content_item_id' => $item->id, 'problems' => $problems]);

            if ($published > 0) {
                $item->changeStatus(ContentStatus::Geplaatst, "Deels geplaatst ({$published} van " . count($ids) . '). ' . implode('; ', $problems));
                $telegram->notify("⚠️ Content #{$item->id} \"{$item->title}\" ({$when}): maar {$published} van " . count($ids) . " netwerken geplaatst.\n" . implode("\n", $problems));
            } else {
                $item->changeStatus(ContentStatus::Mislukt, 'Niet gepubliceerd: ' . implode('; ', $problems));
                $telegram->notify("❌ Content #{$item->id} \"{$item->title}\" ({$when}) is NIET gepubliceerd.\n" . implode("\n", $problems));
            }
        }

        return self::SUCCESS;
    }
}
