<?php

return [

    // The audio overview: at most this many characters of script in total (it bounds the speech cost).
    'audio_max_chars' => (int) env('STUDIO_AUDIO_MAX_CHARS', 5_000),
    'audio_max_turns' => (int) env('STUDIO_AUDIO_MAX_TURNS', 24),

    // At most this many items may be waiting or running in one notebook at a time.
    'max_in_flight' => 2,

    // Output caps of the structured kinds. Claude Sonnet 5.5 (pro) thinks before it writes and the thinking counts against the cap: a 2,500-token cap
    // cut a real Dez script mid-string after about 560 visible tokens and was still billed in full (2026-11-18). Each cap is the visible need plus about 4,000.
    // Reserved on pro at $10 per million output tokens: audio $0.065, quiz $0.07, slides $0.08, mind map $0.06, a report $0.07 (the old caps: 0.025, 0.03, 0.04, 0.02, 0.03).
    // Reports can be long: the most a report may produce (tokens), used to estimate its cost before the call.
    'report_max_output_tokens' => (int) env('STUDIO_REPORT_MAX_OUTPUT_TOKENS', 16_000),

    // The name a new item of each kind starts with. A kind added only to 'kinds' gets its name made from the kind (study_guide -> Study Guide).
    'titles' => ['audio' => 'Audio overview', 'briefing' => 'Briefing', 'study_guide' => 'Study guide', 'faq' => 'FAQ', 'mindmap' => 'Mind map', 'quiz' => 'Quiz', 'slides' => 'Slide deck', 'infographic' => 'Infographic'],

    'kinds' => ['audio', 'briefing', 'study_guide', 'faq', 'mindmap', 'quiz', 'slides', 'infographic'],

    // A mind map: at most this many nodes, this many levels below the title.
    'mindmap_max_nodes' => (int) env('STUDIO_MINDMAP_MAX_NODES', 40),
    'mindmap_max_depth' => 3,

    // The quiz (D-033): 5 to 8 questions, four options each.
    // Limits the generator and the estimate must agree on: one place, so the estimate's "same hold" cannot drift from the real one.
    'audio_script_max_output_tokens' => 20_000,
    'mindmap_max_output_tokens' => 16_000,
    'passages_per_item' => 24, // the best passages sent when the sources do not fit whole

    // D-054: a model cannot count characters, so nothing it writes is cut. The prompts say "short"; code refuses only beyond these generous hard maximums
    // (one repair call with the checker's own words, then a clean failure). The old silent cuts were 300 / 200 / 300 / 500 / 120 and 120.
    'quiz_hard_max' => ['title' => 200, 'question' => 600, 'option' => 400, 'hint' => 600, 'explanation' => 1000],
    'mindmap_hard_max' => ['title' => 200, 'label' => 200],

    'quiz_min_questions' => 5,
    'quiz_max_questions' => 8,
    // The slide deck (D-034): the contract itself is App\Studio\SlideContract.
    'slides_max_output_tokens' => (int) env('STUDIO_SLIDES_MAX_OUTPUT_TOKENS', 16_000),

    'quiz_max_output_tokens' => (int) env('STUDIO_QUIZ_MAX_OUTPUT_TOKENS', 16_000),

];
