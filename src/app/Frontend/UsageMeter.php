<?php

namespace App\Frontend;

use App\Ai\Ledger;
use App\Models\User;

/** What the user has spent this month against their cap, for the meter in the interface (from the ledger). */
class UsageMeter
{
    public function __construct(private Ledger $ledger) {}

    /** @return array{tier: string, label: string, spent_usd: float, cap_usd: float, percent: int, resets_at: string} */
    public function for(User $user): array
    {
        $spent = $this->ledger->spent($user);
        $cap = max(1, $this->ledger->capFor($user));
        $role = (string) $user->getAttribute('role');

        return [
            'tier' => $user->effectiveTier(),
            // What to call the budget: reviewers and the owner have their own, whatever their tier.
            'label' => array_key_exists($role, config('ledger.role_caps')) ? $role : $user->effectiveTier(),
            'spent_usd' => round($spent / 1_000_000, 4),
            'cap_usd' => round($cap / 1_000_000, 2),
            'percent' => (int) min(100, round($spent / $cap * 100)),
            'resets_at' => $this->ledger->periodStart()->copy()->addMonth()->toIso8601String(),
        ];
    }
}
