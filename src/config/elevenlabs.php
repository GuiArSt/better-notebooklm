<?php

// ElevenLabs Text to Dialogue (Eleven v3, two hosts): the podcast voice for reviewers, the owner and pro users (D-014).
// The key goes in the untracked .env, never here. API: https://elevenlabs.io/docs/api-reference/text-to-dialogue/convert
return [

    'key' => env('ELEVENLABS_API_KEY'),

    // An empty value in .env (the template lists the names with nothing after them) counts as not set: `?:`, not env()'s default.
    'base_url' => env('ELEVENLABS_BASE_URL') ?: 'https://api.elevenlabs.io',

    // Eleven v4 (D-049, the owner's call): the same price as v3 ($0.08 per 1,000 characters, config/pricing.php) and a 10,000-character dialogue limit. ELEVENLABS_MODEL overrides.
    'model' => env('ELEVENLABS_MODEL') ?: 'eleven_v4',

    'output_format' => env('ELEVENLABS_OUTPUT_FORMAT') ?: 'mp3_44100_128',

    // Voice ids for host A and host B. Defaults are two of ElevenLabs' premade voices that exist on the owner's account (my first guesses did not: voice_not_found); the owner may choose others.
    'voices' => [
        'a' => env('ELEVENLABS_VOICE_A') ?: 'XrExE9yKIg1WjnnlVkGX', // Matilda, premade (found on the owner's account by listing /v2/voices)
        'b' => env('ELEVENLABS_VOICE_B') ?: 'iP95p4xoKVk53GoZ742B', // Chris, premade
    ],

    // The four pro personas' voices (D-039): stock voices, never clones, chosen to the owner's description of each, in English. The voice replaces host B.
    // Dez: Brian (American, middle-aged, deep, resonant). Sir Alistair: George (British, warm storyteller). Rosa: Bella (American, middle-aged, warm, bright).
    // Prof. Varga: Sarah (American, mature, confident). No accent: the owner declined adding a Voice Library voice, so the persona is carried by cadence and words.
    'persona_voices' => [
        'dez' => env('ELEVENLABS_VOICE_DEZ') ?: 'nPczCjzI2devNBz1zQrb',
        'alistair' => env('ELEVENLABS_VOICE_ALISTAIR') ?: 'JBFqnCBsd6RMkjVDRZzb',
        'varga' => env('ELEVENLABS_VOICE_VARGA') ?: 'EXAVITQu4vr4xnSDxMaL',
        'rosa' => env('ELEVENLABS_VOICE_ROSA') ?: 'hpp4J3VqNfWAUOO0d1Us',
    ],

    // The audio tags a script may keep (Eleven v3 reads them as delivery); any other [bracket] is stripped before speech, and at most one is kept per turn.
    // Tried one by one on dev, 2026-11-18, against a no-tag control (speech-to-text on the result): none was read aloud; laughs came back as a laugh, sighs and
    // excited and pause changed the audio. [deadpan] and [thoughtful] changed nothing a machine could tell (not v3's documented tags) and are out.
    // Re-tried on eleven_v4 (2026-11-19, dev, same method): laughs, chuckles (heard as a laugh), sighs and pause and excited all still act; [whispers] left the
    // audio byte-identical to the no-tag control, so it is out too.
    'tags' => ['laughs', 'chuckles', 'sighs', 'pause', 'excited'],

    // Eleven v3 advised at most 2,000 characters a request; v4 allows 10,000 (D-049). 4,000 keeps a call near a minute (measured on dev: 1,500 characters took
    // about 22 s) under the 120 s timeout below; a longer script goes in several calls, whose voices are fixed per speaker.
    'max_chars_per_request' => (int) env('ELEVENLABS_MAX_CHARS_PER_REQUEST', 4_000),

    'timeout' => 120,

    // Who hears this voice: these roles and the pro tier. Everyone else keeps Mistral's voice (D-005, D-014).
    'roles' => ['owner', 'reviewer'],

];
