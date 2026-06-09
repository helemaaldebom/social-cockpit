<?php

namespace App\Console\Commands;

use App\Jobs\FetchRssFeedsJob;
use Illuminate\Console\Command;

class FetchRssFeedsCommand extends Command
{
    protected $signature = 'social:fetch-rss';
    protected $description = 'Haal RSS-feeds op en genereer content items';

    public function handle(): int
    {
        FetchRssFeedsJob::dispatch();
        $this->info('RSS-fetch gestart.');
        return self::SUCCESS;
    }
}
