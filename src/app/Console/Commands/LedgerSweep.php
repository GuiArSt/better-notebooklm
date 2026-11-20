<?php

namespace App\Console\Commands;

use App\Ai\Ledger;
use Illuminate\Console\Command;

class LedgerSweep extends Command
{
    protected $signature = 'ledger:sweep {--minutes=15}';

    protected $description = 'Settle spending holds older than 15 minutes at their reserved amount (a worker killed between reserve and settle)';

    public function handle(Ledger $ledger): int
    {
        $this->info('Swept '.$ledger->sweep((int) $this->option('minutes')).' stale hold(s) at their reserved amount.');

        return self::SUCCESS;
    }
}
