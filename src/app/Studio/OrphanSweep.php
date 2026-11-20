<?php

namespace App\Studio;

use App\Ai\Ledger;
use App\Ai\Reservation;
use App\Icons\GenerateNotebookIcon;
use App\Models\Notebook;
use App\Models\NotebookOverview;
use App\Models\Source;
use App\Models\StudioItem;
use App\Sources\BuildOverview;
use App\Sources\EmbedSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An item or a source left "pending" or "running" for ever (D-038): a worker killed or restarted mid-job leaves no one to say it failed, and the
 * person looking at it sees a spinner without end. Any such row that is older than its job's timeout plus a margin, AND has no job waiting or
 * reserved in the queue, is marked failed with a plain sentence. A live job (reserved or waiting, even a slow one) is never touched.
 *
 * Its money too: a killed job leaves the hold it made on the user's budget. The calls it really made are already in the ledger at their real
 * cost, so the hold is RELEASED (never charged in full on top). Only holds tagged with the item are touched; a live job's hold never is.
 */
class OrphanSweep
{
    public const MESSAGE = 'Interrupted, please try again.';

    /** Seconds past the job's own timeout before a row with no job is given up on. */
    public const MARGIN_SECONDS = 300;

    /** @return array{items: list<int>, sources: list<int>, icons: list<int>, overviews: list<int>} the ids marked failed */
    public function run(): array
    {
        $live = $this->idsInTheQueue();

        $items = $this->orphans(StudioItem::query()->whereIn('status', ['pending', 'running']), $this->seconds(GenerateStudioItem::class), $live['items']);
        $sources = $this->orphans(Source::query()->where('status', 'pending'), $this->seconds(EmbedSource::class), $live['sources']);

        foreach ($items as $item) {
            $item->update(['status' => 'failed', 'step' => null, 'error' => self::MESSAGE]);
            $released = $this->releaseHolds($item);
            // Ids and fixed words only, never text a user wrote.
            Log::warning('Orphaned studio item marked failed', ['item_id' => $item->id, 'kind' => $item->kind, 'was' => $item->getOriginal('status'), 'released_micro_usd' => $released]);
        }
        foreach ($sources as $source) {
            $source->update(['status' => 'failed', 'error' => self::MESSAGE]);
            Log::warning('Orphaned source marked failed', ['source_id' => $source->id, 'kind' => $source->kind]);
        }

        // A notebook's icon and its overview have a state too, and a lost job leaves either one "queued", "running" or "building" for ever.
        $icons = $this->orphans(Notebook::query()->whereIn('icon_status', ['queued', 'running']), $this->seconds(GenerateNotebookIcon::class), $live['icons']);
        foreach ($icons as $notebook) {
            // Straight to the row: icon_status is not mass assignable (only the icon request and job flip it), so $notebook->update() would drop it.
            Notebook::query()->whereKey($notebook->id)->update(['icon_status' => 'failed', 'icon_error' => 'Interrupted']);
            Log::warning('Orphaned notebook icon marked failed', ['notebook_id' => $notebook->id, 'was' => $notebook->getOriginal('icon_status')]);
        }
        $overviews = $this->orphans(NotebookOverview::query()->where('status', 'building'), $this->seconds(BuildOverview::class), $live['overviews'], 'notebook_id');
        foreach ($overviews as $overview) {
            NotebookOverview::mark((int) $overview->notebook_id, 'failed');
            Log::warning('Orphaned notebook overview marked failed', ['notebook_id' => $overview->notebook_id]);
        }

        return [
            'items' => $items->pluck('id')->all(), 'sources' => $sources->pluck('id')->all(),
            'icons' => $icons->pluck('id')->all(), 'overviews' => $overviews->pluck('notebook_id')->all(),
        ];
    }

    /**
     * Release every hold made for this item through the Ledger's own API. Safe to call twice: a hold already released is simply not there.
     * Used by the sweep AND by the job's own failed(), so a worker killed and then reported by the queue frees its money the same way.
     *
     * @return int micro-USD released
     */
    public function releaseHolds(StudioItem|int $item): int
    {
        $itemId = $item instanceof StudioItem ? $item->id : $item;
        $ledger = app(Ledger::class);
        $released = 0;

        foreach (DB::table('ledger_reservations')->where('studio_item_id', $itemId)->get() as $hold) {
            $ledger->release(new Reservation((int) $hold->id, (int) $hold->user_id, (int) $hold->amount_micro_usd));
            $released += (int) $hold->amount_micro_usd;
        }

        return $released;
    }

    /** A job's timeout, read from the job class itself so the two never drift apart, plus the margin. */
    private function seconds(string $job): int
    {
        return (int) (new \ReflectionClass($job))->getProperty('timeout')->getDefaultValue() + self::MARGIN_SECONDS;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<int>  $live  ids that have a job in the queue (the row's `$idColumn`, which names the same thing as the job does)
     * @return Collection<int, Model>
     */
    private function orphans($query, int $seconds, array $live, string $idColumn = 'id')
    {
        // Strictly older than the limit: a row exactly at it is left for the next sweep.
        return $query->where('updated_at', '<', now()->subSeconds($seconds))->get()->reject(fn ($row) => in_array((int) $row->{$idColumn}, $live, true))->values();
    }

    /** @return array{items: list<int>, sources: list<int>, icons: list<int>, overviews: list<int>} the ids named by a job in the queue, reserved or waiting */
    private function idsInTheQueue(): array
    {
        $ids = ['items' => [], 'sources' => [], 'icons' => [], 'overviews' => []];

        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $command = (string) data_get(json_decode((string) $payload, true), 'data.command', '');
            if (str_contains($command, GenerateStudioItem::class) && preg_match('/"itemId";i:(\d+);/', $command, $m)) {
                $ids['items'][] = (int) $m[1];
            } elseif (str_contains($command, EmbedSource::class) && preg_match('/"sourceId";i:(\d+);/', $command, $m)) {
                $ids['sources'][] = (int) $m[1];
            } elseif (str_contains($command, GenerateNotebookIcon::class) && preg_match('/"notebookId";i:(\d+);/', $command, $m)) {
                $ids['icons'][] = (int) $m[1];
            } elseif (str_contains($command, BuildOverview::class) && preg_match('/"notebookId";i:(\d+);/', $command, $m)) {
                $ids['overviews'][] = (int) $m[1];
            }
        }

        return $ids;
    }
}
