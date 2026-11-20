<?php

namespace App\Studio;

use App\Ai\CostScope;
use App\Ai\FriendlyError;
use App\Ai\SafeLog;
use App\Models\StudioItem;
use App\Models\UsageEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs on `media` for audio (ffmpeg, long) and `default` for reports. One try: a retry would spend twice. */
class GenerateStudioItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $itemId, string $queue)
    {
        $this->onQueue($queue);
    }

    public function handle(StudioGenerator $generator): void
    {
        $item = StudioItem::find($this->itemId);
        if ($item === null) {
            return;
        }

        try {
            CostScope::for($item->id, fn () => $generator->generate($item));
        } catch (Throwable $e) {
            // A cap reached ("You have reached your monthly usage limit.") is an honest message; anything else is logged too.
            SafeLog::failure('Studio item', $e, ['item_id' => $item->id, 'kind' => $item->kind]);
            $item->update(['status' => 'failed', 'step' => null, 'error' => FriendlyError::message($e)]);
        } finally {
            $this->recordCost($item);
        }
    }

    /** What the item cost, from the ledger rows written for it (so two items made at once never mix). */
    private function recordCost(StudioItem $item): void
    {
        $item->refresh();
        $settings = (array) $item->settings;
        $settings['cost_micro_usd'] = (int) UsageEvent::where('studio_item_id', $item->id)->sum('cost_micro_usd');
        $item->update(['settings' => $settings]);
    }

    /** A killed or timed-out worker: the row must not stay "running" forever. */
    public function failed(Throwable $e): void
    {
        StudioItem::where('id', $this->itemId)->whereIn('status', ['pending', 'running'])
            ->update(['status' => 'failed', 'step' => null, 'error' => 'The job did not finish. Please try again.']);

        // Its money too (D-038): the calls it really made are in the ledger at their real cost; the hold it left on the user's budget is released,
        // not charged in full later. Whatever state the row was in, and safe if the orphan sweep already did it.
        $released = app(OrphanSweep::class)->releaseHolds($this->itemId);
        if ($released > 0) {
            Log::warning('A killed studio job: its ledger hold released', ['item_id' => $this->itemId, 'released_micro_usd' => $released]);
        }
    }
}
