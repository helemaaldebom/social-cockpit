<?php

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Wekelijkse review-herinnering: somt per klant op welke posts op goedkeuring
 * wachten. Er wordt niets ingepland of geplaatst — dat gebeurt pas na de
 * handmatige goedkeuring in de admin.
 */
class ReviewDigestCommand extends Command
{
    protected $signature = 'social:review-digest';
    protected $description = 'Stuur het wekelijkse overzicht van posts die op goedkeuring wachten';

    public function handle(TelegramService $telegram): int
    {
        $items = ContentItem::with('client')
            ->where('status', ContentStatus::InReview->value)
            ->orderBy('client_id')
            ->orderBy('created_at')
            ->get();

        $url = rtrim((string) config('app.url'), '/') . '/admin/content-items?activeTab=review';

        if ($items->isEmpty()) {
            $telegram->notify("🗓 Wekelijkse review: er wachten geen posts op goedkeuring.");
            $this->info('Geen items in review.');
            return self::SUCCESS;
        }

        $lines = ["🗓 Wekelijkse review: {$items->count()} post(s) wachten op jouw goedkeuring.", ''];

        foreach ($items->groupBy(fn (ContentItem $i) => $i->client?->name ?? 'Onbekend') as $client => $group) {
            $lines[] = "{$client} ({$group->count()}):";
            foreach ($group as $item) {
                $lines[] = "• #{$item->id} {$item->title}";
            }
            $lines[] = '';
        }

        $lines[] = "Beoordelen en goedkeuren: {$url}";
        $lines[] = 'Zonder goedkeuring wordt er niets geplaatst.';

        $telegram->notify(implode("\n", $lines));
        $this->info("Digest verstuurd ({$items->count()} items).");

        return self::SUCCESS;
    }
}
