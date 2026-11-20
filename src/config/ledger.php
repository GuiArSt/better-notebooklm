<?php

// Hard caps, in micro-USD per calendar month (UTC). D-047: for the demo, room over thrift: a .env value still wins.
return [

    'user_caps' => [
        'free' => (int) env('LEDGER_CAP_FREE', 2_000_000),   // $2.00
        'pro' => (int) env('LEDGER_CAP_PRO', 20_000_000),   // $20.00 per test purchase
    ],

    // Reviewers (the company's testers) and the owner get pro-level features on the owner's budget, so they get their own
    // cap, which is never lower than their tier's (D-012, D-014). The owner can raise his own in .env.
    'role_caps' => [
        'owner' => (int) env('LEDGER_CAP_OWNER', 50_000_000),     // $50.00
        'reviewer' => (int) env('LEDGER_CAP_REVIEWER', 25_000_000), // $25.00
    ],

    // Real spend across everyone: the cap that protects the owner's wallet.
    'global_cap' => (int) env('LEDGER_CAP_GLOBAL', 200_000_000), // $200.00

    // Characters per token when estimating before a call: low on purpose, so the hold errs on the side of too much.
    'chars_per_token' => 2,

];
