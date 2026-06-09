<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\PublerPublisher;
use Illuminate\Console\Command;

class SyncPublerAccountsCommand extends Command
{
    protected $signature = 'social:sync-publer-accounts';
    protected $description = 'Synchroniseer Publer accounts met bestaande kanalen';

    public function handle(PublerPublisher $publisher): int
    {
        $this->info('Ophalen van Publer accounts...');

        try {
            $accounts = $publisher->getAccounts();
        } catch (\Throwable $e) {
            $this->error('Publer API fout: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(count($accounts) . ' account(s) gevonden bij Publer.');

        foreach ($accounts as $account) {
            $id = $account['id'] ?? null;
            $name = $account['name'] ?? 'Onbekend';
            $platform = strtolower($account['platform'] ?? '');

            $channel = Channel::where('name', 'LIKE', "%{$name}%")
                ->orWhere('network', $platform)
                ->whereNull('publer_account_id')
                ->first();

            if ($channel) {
                $channel->update(['publer_account_id' => $id]);
                $this->line("✓ Gekoppeld: {$channel->name} → Publer #{$id}");
            } else {
                $this->warn("⚠ Niet automatisch gematcht: {$name} (ID: {$id}, platform: {$platform})");
            }
        }

        $this->info('Klaar.');
        return self::SUCCESS;
    }
}
