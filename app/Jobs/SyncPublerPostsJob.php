<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ContentItem;
use App\Services\PublerPublisher;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Importeert posts die buiten de Cockpit om — handmatig in Publer — zijn
 * ingepland automatisch als Cockpit-ContentItem, zodat ze (a) zichtbaar zijn
 * in de admin, (b) meetellen bij slot-bezetting en (c) een Telegram-preview
 * krijgen 22u voor publicatie.
 *
 * Draait uurlijks (zie routes/console.php). Volledig idempotent: posts waarvan
 * de Publer-id al aan een (ook soft-deleted) item hangt, worden overgeslagen.
 *
 * Deze job VERANDERT NOOIT iets in Publer — alleen lezen. Posts worden enkel
 * in de Cockpit gespiegeld.
 */
class SyncPublerPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(PublerPublisher $publer): void
    {
        $alreadyClaimed = ContentItem::claimedPublerPostIds();
        $claimedLookup = array_flip($alreadyClaimed);

        foreach (Client::where('active', true)->get() as $client) {
            $channels = $client->channels()
                ->whereNotNull('publer_account_id')
                ->get();

            if ($channels->isEmpty()) {
                continue;
            }

            // account_id => Channel (één kanaal per Publer-account per klant)
            $channelByAccount = $channels->keyBy('publer_account_id');
            $accountIds = $channelByAccount->keys()->all();

            $posts = $publer->scheduledPostsForAccounts($accountIds);

            // Groepeer per logische post. Publer geeft alle netwerk-varianten
            // van één post hetzelfde group_id; valt dat weg, dan groeperen we
            // op tijdstip + tekst zodat FB/IG/LI samen één item worden.
            $groups = [];
            foreach ($posts as $post) {
                // Al gekoppeld aan een Cockpit-item? Overslaan.
                if (isset($claimedLookup[$post['id']])) {
                    continue;
                }
                // Alleen toekomstige posts; verleden heeft geen reminder nodig.
                if ($post['scheduled_at']->isPast()) {
                    continue;
                }

                $key = $post['group_id'] !== ''
                    ? $post['group_id']
                    : $post['scheduled_ts'] . '|' . md5($post['text']);

                $groups[$key][] = $post;
            }

            foreach ($groups as $group) {
                $this->importGroup($client, $group, $channelByAccount);
            }
        }
    }

    /**
     * @param  array<int,array>  $group  posts met hetzelfde group_id
     * @param  \Illuminate\Support\Collection<string,Channel>  $channelByAccount
     */
    private function importGroup(Client $client, array $group, $channelByAccount): void
    {
        $first = $group[0];
        $scheduledAt = $first['scheduled_at']->copy(); // UTC Carbon
        $postIds = array_values(array_unique(array_map(fn ($p) => $p['id'], $group)));

        // Kanalen die bij deze post horen (op basis van account_id).
        $channelIds = [];
        foreach ($group as $post) {
            $channel = $channelByAccount->get($post['account_id']);
            if ($channel) {
                $channelIds[$channel->id] = $channel->id;
            }
        }
        $channelIds = array_values($channelIds);

        $title = $this->makeTitle($first['text']);

        try {
            $item = new ContentItem();
            $item->client_id = $client->id;
            $item->title = $title;
            $item->brief = 'Geïmporteerd uit Publer (handmatig ingepland).';
            $item->original_text = $first['text'];
            $item->generated_text = $first['text'];
            $item->status = ContentStatus::Concept;
            $item->save();

            if ($channelIds) {
                $item->channels()->sync($channelIds);
            }

            // Handgeschreven/extern → concept direct naar goedgekeurd, dan ingepland.
            $item->changeStatus(ContentStatus::Goedgekeurd, 'Geïmporteerd uit Publer (handmatig ingepland).');

            $item->publer_post_ids = $postIds;
            $item->publer_post_id = $postIds[0] ?? null;
            $item->scheduled_for = $scheduledAt; // mutator zet naar UTC
            $item->save();

            $item->changeStatus(ContentStatus::Ingepland, 'Automatisch gesynct vanuit Publer.');

            // Preview 22u voor publicatie; is dat moment al voorbij, dan nu
            // dispatchen zodat de reminder alsnog (vrijwel direct) uitgaat.
            $previewAt = $scheduledAt->copy()->subHours(22);
            SendTelegramPreviewJob::dispatch($item)->delay(
                $previewAt->isFuture() ? $previewAt : now()
            );

            Log::info('SyncPublerPostsJob: post geïmporteerd', [
                'content_item_id' => $item->id,
                'client'          => $client->name,
                'scheduled_for'   => $scheduledAt->toIso8601String(),
                'publer_post_ids' => $postIds,
            ]);
        } catch (\Throwable $e) {
            Log::error('SyncPublerPostsJob: import mislukt', [
                'client'          => $client->name,
                'publer_post_ids' => $postIds,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function makeTitle(string $text): string
    {
        $firstLine = trim(strtok($text, "\n") ?: $text);
        $firstLine = preg_replace('/\s+/', ' ', $firstLine);
        $short = mb_substr($firstLine, 0, 60);
        if (mb_strlen($firstLine) > 60) {
            $short .= '…';
        }

        return trim($short) . ' [handmatig via Publer]';
    }
}
