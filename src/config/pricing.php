<?php

// Micro-USD (1 USD = 1,000,000) per one million tokens. Read from the providers' price pages on the date shown;
// a change in price is a change here. A model not listed costs the 'default' rate, so an unknown model is never free.
return [

    'models' => [
        // Mistral Small, read 2026-11-17: $0.15 in / $0.60 out per million tokens.
        'mistral-small-latest' => ['input' => 150_000, 'output' => 600_000],
        // mistral-embed, read 2026-11-17: $0.10 per million tokens.
        'mistral-embed' => ['input' => 100_000, 'output' => 0],
        // Claude Sonnet 5.5, read 2026-11-17: $2 in / $10 out.
        'claude-sonnet-5-5' => ['input' => 2_000_000, 'output' => 10_000_000],
        // Mistral Large (D-049: free-tier slides), $0.70 in / $2.10 out per million tokens as the owner's brief gives them. NOT read from Mistral's page by me.
        'mistral-large-latest' => ['input' => 700_000, 'output' => 2_100_000],
        // Claude Haiku 5.5, read 2026-11-17: $0.10 in / $0.50 out (up to 100k).
        'claude-haiku-5-5' => ['input' => 100_000, 'output' => 500_000],
    ],

    // Speech, micro-USD per one million characters. Voxtral TTS, read 2026-11-17: $13 per million characters.
    'speech' => [
        'voxtral-mini-tts-3-0' => 13_000_000,
        'eleven_v3' => 80_000_000, // UNVERIFIED: see the note below
        'eleven_v4' => 80_000_000, // the same $0.08 per 1,000 characters as v3 (Vellum read it from ElevenLabs' pricing page, D-049); credits per character not yet measured
    ],
    // ElevenLabs Eleven v3 (also its multi-speaker Text to Dialogue): 80,000,000 micro-USD per million characters = 80 per character
    // ($0.08 per 1,000 characters), see 'eleven_v3' in 'speech' above.
    // UNVERIFIED, from a web search result, 2026-11-17 (about https://elevenlabs.io/pricing/api; an older cached copy said $0.10; I never opened
    // the live page). ElevenLabs bills CREDITS, not characters. Measured 2026-11-18 through the API: eleven_v3 dialogue uses about 0.22 credits per
    // character (35 characters = 8 credits, 266 = 58, read from its `character-cost` response header), so the dollars per character depend on what a
    // credit costs on the owner's plan, which this key cannot read. The ledger records the real credits per call (usage_events.units.credits).
    'speech_default' => 30_000_000,

    // Images, micro-USD PER IMAGE, by model, quality and size. The Router refuses an image model that is not listed here, before the call leaves.
    // gpt-image-2.5-flare, low quality, 1024x1024: about $0.00588 per image (196 output tokens at $30 per million), rounded up to $0.006.
    // UNVERIFIED: from third-party calculators found by web search on 2026-11-18, not from OpenAI's own page (a comparison site notes that OpenAI
    // does not publish a per-image table for the 2.5 snapshots). The ledger writes this figure; the owner should check it against the invoice.
    // Rectangles (1536x1024, 1024x1536) and medium quality are Vesper's proposal for the infographics (D-045): ALSO UNVERIFIED, same source and date.
    // gpt-image-2.6-flare (D-049) has the same token prices as 2.5-flare (Vellum's reading), so the same rows: UNVERIFIED like them.
    'images' => [
        'gpt-image-2.5-flare' => [
            'low' => ['1024x1024' => 6_000, '1536x1024' => 9_000, '1024x1536' => 9_000],
            'medium' => ['1024x1024' => 23_000, '1536x1024' => 35_000, '1024x1536' => 35_000],
        ],
        'gpt-image-2.6-flare' => [
            'low' => ['1024x1024' => 6_000, '1536x1024' => 9_000, '1024x1536' => 9_000],
            'medium' => ['1024x1024' => 23_000, '1536x1024' => 35_000, '1024x1536' => 35_000],
        ],
    ],

    'default' => ['input' => 2_000_000, 'output' => 10_000_000],

];
