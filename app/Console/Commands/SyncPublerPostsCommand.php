<?php

namespace App\Console\Commands;

use App\Jobs\SyncPublerPostsJob;
use Illuminate\Console\Command;

class SyncPublerPostsCommand extends Command
{
    protected $signature = 'social:sync-publer {--sync : Draai synchroon i.p.v. via de queue}';
    protected $description = 'Importeer handmatig in Publer ingeplande posts als Cockpit-item (incl. Telegram-preview)';

    public function handle(): int
    {
        if ($this->option('sync')) {
            SyncPublerPostsJob::dispatchSync();
            $this->info('Publer-sync synchroon uitgevoerd.');
        } else {
            SyncPublerPostsJob::dispatch();
            $this->info('Publer-sync gestart (queue).');
        }

        return self::SUCCESS;
    }
}
