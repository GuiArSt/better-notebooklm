<?php

/**
 * Generated notebook icons (D-032): OpenAI's image model draws one small icon per new notebook, in place of the emoji.
 * Off by default: the switch is NOTEBOOK_ICONS, and it needs OPENAI_API_KEY. Every failure keeps the emoji.
 */
return [
    'enabled' => (bool) env('NOTEBOOK_ICONS', false),

    // The model id is never in code. D-049 (the owner: "change to gpt-image 2.6"): 2.6-flare by default; IMAGE_MODEL still overrides it, and 2.5-flare
    // keeps its price rows so it can be put back.
    'model' => env('IMAGE_MODEL') ?: 'gpt-image-2.6-flare',
    'size' => '1024x1024',
    'quality' => 'low',

    // What the whole app may spend on images (icons and infographics) per day, micro-USD (default $5.00, D-047). Past it, a request ends "failed":
    // an icon keeps its emoji.
    'daily_cap_micro_usd' => (int) env('IMAGE_DAILY_CAP', 5_000_000),

    // The stored icon: a 256 px WebP (PNG when this PHP has no GD with WebP), on the app's own private disk.
    'stored_px' => 256,
    'timeout_seconds' => 240,

    'style' => 'flat line icon, two colours, centred on a plain background, no text, no letters, no logos',
];
