<?php

namespace App\Console\Commands;

use App\Studio\OrphanSweep;
use Illuminate\Console\Command;

class SweepOrphans extends Command
{
    protected $signature = 'orphans:sweep';

    protected $description = 'Mark failed any studio item or source stuck pending or running with no job behind it (a killed or restarted worker)';

    public function handle(OrphanSweep $sweep): int
    {
        $done = $sweep->run();
        $this->info(count($done['items']).' studio item(s) and '.count($done['sources']).' source(s) marked failed.');
        if ($done['icons'] !== [] || $done['overviews'] !== []) {
            $this->info(count($done['icons']).' notebook icon(s) and '.count($done['overviews']).' overview(s) marked failed.');
        }

        return self::SUCCESS;
    }
}
