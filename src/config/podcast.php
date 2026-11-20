<?php

// The podcast's formats and lengths (D-039). The length of an episode is code, never prose (D-037): the owner's ElevenLabs credits for the whole demo
// are the binding limit, so the formats differ by structure, not by length. Voices and tags are in config/elevenlabs.php.
return [

    // Deep Dive is the default; Brief starts at the short length unless the user chose one. The text of each format is resources/prompts/formats/<key>.md.
    'formats' => ['deep_dive' => 'Deep Dive', 'brief' => 'Brief', 'critique' => 'Critique', 'debate' => 'Debate'],
    'default_format' => 'deep_dive',
    'brief_length' => 'short',

    // The four pro personas (D-039), each replacing host B: the name and the line the Customize dialog shows. The bible is resources/prompts/personas/<key>.md,
    // the voice is config/elevenlabs.php persona_voices. Only whoever hears ElevenLabs may choose one (pro, owner, reviewer).
    'personas' => [
        'dez' => ['gender' => 'male', 'name' => 'Dez Calloway', 'role' => 'The late-night host', 'line' => 'Read it all, then noticed how absurd it is.'],
        'alistair' => ['gender' => 'male', 'name' => 'Sir Alistair Fenwick-Hale', 'role' => 'The naturalist', 'line' => 'Observes people as a species, gently.'],
        'varga' => ['gender' => 'female', 'name' => 'Prof. Ines Varga', 'role' => 'The philosopher', 'line' => 'Picks a school of thought to fit your sources.'],
        'rosa' => ['gender' => 'female', 'name' => 'Rosa Delgado', 'role' => 'The mother of five', 'line' => 'Explains it plainly, then gets on with her day.'],
    ],

    // The hosts' voices are never two of one gender (release 3): host A and B come from a female and a male pool per provider. The default pools are
    // config/elevenlabs.php voices (a female, b male) and config/models.php voices (a female, b male); a language that needs its own voices lists them here
    // as host_voices.<provider>.<code> => ['female' => id, 'male' => id]. Podcast::pool fails loudly when a gender is missing.
    'host_voices' => [
        'elevenlabs' => [],
        'mistral' => [],
    ],

    // Short, Default, Long: at most this many turns and characters of script. Long is the ceiling in config/studio.php (audio_max_chars).
    'lengths' => [
        'short' => ['turns' => 10, 'chars' => 2_000],
        'default' => ['turns' => 17, 'chars' => 3_500],
        'long' => ['turns' => 24, 'chars' => 5_000],
    ],

];
