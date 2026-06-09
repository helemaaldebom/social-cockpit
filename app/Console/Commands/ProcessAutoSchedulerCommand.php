<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAutoSchedulerJob;
use Illuminate\Console\Command;

class ProcessAutoSchedulerCommand extends Command
{
    protected $signature = 'social:process-scheduler';
    protected $description = 'Verwerk publish slots en lever goedgekeurde content aan bij Publer';

    public function handle(): int
    {
        ProcessAutoSchedulerJob::dispatch();
        $this->info('Auto-scheduler gestart.');
        return self::SUCCESS;
    }
}
