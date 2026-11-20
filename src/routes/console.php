<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ledger:sweep')->everyFiveMinutes();

// A worker killed mid-job leaves an item or a source pending or running for ever: fail the ones with no job behind them (D-038).
Schedule::command('orphans:sweep')->everyFiveMinutes()->withoutOverlapping();
