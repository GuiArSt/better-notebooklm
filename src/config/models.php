<?php

// Which model answers which call. A model id never goes in code: it goes here and in the untracked .env.
return [

    'chat' => [
        'free' => [
            'provider' => env('AI_CHAT_PROVIDER_FREE', 'mistral'),
            'model' => env('AI_CHAT_MODEL_FREE', 'mistral-small-latest'),
        ],
        'pro' => [
            'provider' => env('AI_CHAT_PROVIDER_PRO', 'anthropic'),
            'model' => env('AI_CHAT_MODEL_PRO', 'claude-sonnet-5-5'),
        ],
    ],

    // Slide decks need a model that keeps to hard counts and lengths. By default the same as chat; set AI_SLIDES_MODEL_FREE (and give it a
    // price in config/pricing.php) to use a larger model for slides only.
    'slides' => [
        'free' => [
            'provider' => env('AI_SLIDES_PROVIDER_FREE') ?: env('AI_CHAT_PROVIDER_FREE', 'mistral'),
            // D-049: Mistral Small could not keep to the slide contract's counts and lengths reliably; Large answers slides for the free tier.
            'model' => env('AI_SLIDES_MODEL_FREE') ?: 'mistral-large-latest',
        ],
        'pro' => [
            'provider' => env('AI_SLIDES_PROVIDER_PRO') ?: env('AI_CHAT_PROVIDER_PRO', 'anthropic'),
            'model' => env('AI_SLIDES_MODEL_PRO') ?: env('AI_CHAT_MODEL_PRO', 'claude-sonnet-5-5'),
        ],
    ],

    'helper' => [
        'free' => [
            'provider' => env('AI_HELPER_PROVIDER_FREE', 'mistral'),
            'model' => env('AI_HELPER_MODEL_FREE', 'mistral-small-latest'),
        ],
        'pro' => [
            'provider' => env('AI_HELPER_PROVIDER_PRO', 'anthropic'),
            'model' => env('AI_HELPER_MODEL_PRO', 'claude-haiku-5-5'),
        ],
    ],

    'embedding' => [
        'provider' => env('AI_EMBEDDING_PROVIDER', 'mistral'),
        'model' => env('EMBEDDING_MODEL', 'mistral-embed'),
    ],

    // Speech for the audio overview: two host voices.
    'speech' => [
        'provider' => env('AI_SPEECH_PROVIDER', 'mistral'),
        'model' => env('AI_SPEECH_MODEL', 'voxtral-mini-tts-3-0'),
    ],

    'voices' => [
        'a' => env('AI_VOICE_A', 'default-female'),
        'b' => env('AI_VOICE_B', 'default-male'),
    ],

    // What an image sent to a model counts for in the cost HOLD, tokens per image (D-057: Claude looks at the picture it briefed). Anthropic counts
    // width x height / 750: 1536x1024 is 2,097 (the infographics), 1024x1024 is 1,398 (an icon). The real cost is what the provider reports.
    'image_input_tokens' => 2_100,

    // Request options that keep Claude from spending its output limit on hidden thinking in JSON-only calls (Router::text), by model id. Verified
    // against the real API on 2026-11-19: Sonnet 5.5 answers 400 to {"thinking": {"type": "disabled"}} and wants "between_tools"; Haiku 5.5 accepts
    // "disabled". A model not listed is sent nothing. Never sent to Mistral or to the streamed chat.
    'structured_call_options' => [
        'claude-sonnet-5-5' => ['thinking' => ['type' => 'between_tools'], 'output_config' => ['effort' => 'low']],
        'claude-haiku-5-5' => ['thinking' => ['type' => 'disabled'], 'output_config' => ['effort' => 'low']],
    ],

    // The most a chat answer (and a summary or overview) may produce; the cost is reserved against it before the call, so the estimate and the
    // limit are the same number. 1,024 is the default (Mistral Small, unchanged: not measured to be short). A provider listed below has its own:
    // Claude's hidden thinking took ~950 of 1,024 and a real pro answer was cut mid-sentence after 38 words (measured on dev, 2026-11-18:
    // output_tokens 1024, 273 visible characters; Tycho LG-1: 4 of 7 pro calls stopped at exactly 1,024). D-047 lifts Claude's to 8,000. Billing is still the real usage.
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1024),
    'max_output_tokens_by_provider' => [
        'anthropic' => (int) env('AI_MAX_OUTPUT_TOKENS_ANTHROPIC', 8000), // D-047: room for hidden thinking AND a long answer
    ],

];
