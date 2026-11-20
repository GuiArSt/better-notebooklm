<?php

namespace App\Ai;

use App\Ai\Exceptions\CapExceeded;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;

/**
 * Reserve, then settle. A call is refused before it starts if the user's cap or the global cap would be crossed.
 * One advisory lock serialises reservations, so two calls can never both squeeze under a cap.
 */
#[Singleton]
class Ledger
{
    /** An audio episode reserves its whole cost up front; the calls inside it draw on that, so nothing is counted twice. */
    private ?Reservation $episode = null;

    private int $episodeRemaining = 0;

    public function periodStart(): CarbonInterface
    {
        return now('UTC')->startOfMonth();
    }

    public function spent(?User $user = null): int
    {
        $start = $this->periodStart();

        $events = UsageEvent::query()->where('created_at', '>=', $start)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))->sum('cost_micro_usd');
        $held = DB::table('ledger_reservations')->where('created_at', '>=', $start)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))->sum('amount_micro_usd');

        return (int) $events + (int) $held;
    }

    public function capFor(User $user): int
    {
        // A role with a cap of its own (reviewer, owner) has THAT cap, whatever tier it counts as: since D-048 they are pro by role, and the pro
        // tier's cap must not swallow a role cap that is set lower, or hide one the owner raised. Everyone else has their tier's cap.
        $byRole = (int) config('ledger.role_caps.'.$user->getAttribute('role'), 0);
        if ($byRole > 0) {
            return $byRole;
        }

        return (int) config('ledger.user_caps.'.$user->effectiveTier(), config('ledger.user_caps.free'));
    }

    /**
     * Reserve the whole estimated cost of a multi-call job (an audio episode) before its first call, so a job that cannot be
     * afforded is refused before anything is spent. Until endEpisode(), calls for the same user draw on it.
     *
     * @throws CapExceeded
     */
    public function beginEpisode(User $user, int $estimate): void
    {
        $this->episode = $this->reserve($user, $estimate);
        $this->episodeRemaining = $estimate;
    }

    public function endEpisode(): void
    {
        if ($this->episode !== null) {
            $this->release($this->episode);
        }
        $this->episode = null;
        $this->episodeRemaining = 0;
    }

    /** @throws CapExceeded */
    public function reserve(User $user, int $estimate): Reservation
    {
        if ($this->episode !== null && $this->episode->userId === $user->id && $estimate <= $this->episodeRemaining) {
            $this->episodeRemaining -= $estimate;

            return new Reservation(null, $user->id, $estimate, true);
        }

        return DB::transaction(function () use ($user, $estimate) {
            DB::select('select pg_advisory_xact_lock(?)', [7_001]);

            if ($this->spent() + $estimate > (int) config('ledger.global_cap')) {
                throw new CapExceeded('global');
            }
            if ($this->spent($user) + $estimate > $this->capFor($user)) {
                throw new CapExceeded('user');
            }

            $id = DB::table('ledger_reservations')->insertGetId([
                'user_id' => $user->id, 'amount_micro_usd' => $estimate, 'created_at' => now(),
                // Which studio item the hold is for (null for a chat answer or a source): a killed job's holds are released with the item.
                'studio_item_id' => CostScope::itemId(),
            ]);

            return new Reservation($id, $user->id, $estimate);
        });
    }

    /**
     * Write the real cost and free the hold.
     *
     * @param  array<string, mixed>  $event
     */
    public function settle(Reservation $reservation, array $event): UsageEvent
    {
        if ($reservation->fromEpisode) {
            // What the call did not use goes back to the episode's reservation.
            $this->episodeRemaining += max(0, $reservation->amount - (int) ($event['cost_micro_usd'] ?? 0));
        }

        return DB::transaction(function () use ($reservation, $event) {
            if ($reservation->id !== null) {
                DB::table('ledger_reservations')->where('id', $reservation->id)->delete();
            }

            return UsageEvent::create($event + ['user_id' => $reservation->userId, 'studio_item_id' => CostScope::itemId()]);
        });
    }

    /**
     * Settle holds left behind by a call that never settled (a killed worker, a timeout), at the reserved amount.
     * The money may have been spent at the provider, so it stays counted: the sweep frees the hold, never the spend.
     */
    public function sweep(int $olderThanMinutes = 15): int
    {
        return DB::transaction(function () use ($olderThanMinutes) {
            // A hold that belongs to a studio item is not swept here: the orphan sweep releases it with the item (the calls the job really made are
            // already in the ledger at their real cost, so charging the whole hold on top would pay twice).
            $stale = DB::table('ledger_reservations')->whereNull('studio_item_id')->where('created_at', '<', now()->subMinutes($olderThanMinutes))->lockForUpdate()->get();

            foreach ($stale as $hold) {
                UsageEvent::create([
                    'user_id' => $hold->user_id, 'provider' => 'unknown', 'model' => 'unknown', 'kind' => 'unsettled',
                    'units' => ['reason' => 'a call never settled', 'reserved_micro_usd' => (int) $hold->amount_micro_usd],
                    'cost_micro_usd' => (int) $hold->amount_micro_usd, 'key_owner' => 'app',
                ]);
            }
            DB::table('ledger_reservations')->whereIn('id', $stale->pluck('id'))->delete();

            return $stale->count();
        });
    }

    /** A call that failed: free the hold, spend nothing. */
    public function release(Reservation $reservation): void
    {
        if ($reservation->fromEpisode) {
            $this->episodeRemaining += $reservation->amount;
        }
        if ($reservation->id !== null) {
            DB::table('ledger_reservations')->where('id', $reservation->id)->delete();
        }
    }
}
