<?php

/**
 * Infographics (D-045): a short cited brief written by the text model, then ONE picture drawn from that brief alone by OpenAI's image model.
 * Off by default: INFOGRAPHICS_ENABLED. In prod it stays off until the OpenAI DPA is executed (Q-028). Nothing here is a secret.
 */
return [
    'enabled' => (bool) env('INFOGRAPHICS_ENABLED', false),

    // Who may make one: the pro tier and these roles (same people as the ElevenLabs voice).
    'roles' => ['owner', 'reviewer'],

    // The model id is never in code. It must be priced in config/pricing.php or the Router refuses the call.
    'model' => env('INFOGRAPHIC_MODEL') ?: 'gpt-image-2.6-flare',

    // The image model draws on three canvases only. Orientation picks the canvas.
    'orientations' => ['landscape' => '1536x1024', 'portrait' => '1024x1536', 'square' => '1024x1024'],
    'default_orientation' => 'landscape',

    // Level of detail: how many facts the brief holds, and the image quality (higher quality draws small text better, and costs more).
    'details' => [
        'concise' => ['points' => 4, 'quality' => 'low'],
        'standard' => ['points' => 5, 'quality' => 'low'],
        'detailed' => ['points' => 6, 'quality' => 'medium'],
    ],
    'default_detail' => 'standard',

    // Our own named styles. `auto` sends no style text: the model picks one that suits the brief.
    'default_style' => 'auto',
    'styles' => [
        'auto' => ['label' => 'Auto-select', 'text' => 'Choose the visual style that suits the facts best.'],
        'editorial' => ['label' => 'Clean editorial', 'text' => 'Clean editorial style: a generous grid, one strong accent colour on white, bold sans-serif headings, large numerals, thin rules, calm and magazine-like.'],
        'sketch' => ['label' => 'Sketch notes', 'text' => 'Hand-drawn sketch-note style: black ink on off-white paper, wobbly underlines, little doodled arrows and boxes, one highlighter colour.'],
        'clay' => ['label' => 'Clay', 'text' => 'Soft clay style: friendly rounded shapes that look moulded from modelling clay, matte pastel colours, gentle shadows.'],
        'icons' => ['label' => 'Flat icons', 'text' => 'Flat icon style: simple geometric pictograms, a limited bright palette, solid shapes without outlines, tidy columns.'],
        'blueprint' => ['label' => 'Blueprint', 'text' => 'Blueprint style: white technical line drawing on deep blue, thin grid lines, monospaced labels, measured callouts.'],
        'paper' => ['label' => 'Warm paper', 'text' => 'Warm paper style: cream textured paper, earthy terracotta, ochre and olive tones, a printed-poster feel, serif headings.'],
    ],

    // Always sent with the style (the owner's rule: no real people's likeness, no logos).
    'guardrail' => 'Show no real person or likeness of one, and no logos or brand marks. Use only the words and numbers given; add no other text.',

    // The description box: plain text, fenced like source text. Same limit as the focus of every other kind.
    'description_max' => 500,

    // D-057: the same chat model that wrote the brief looks at the picture next to it: good or bad. At most this many drawings; bad twice keeps the second.
    'max_attempts' => 2,
    'review_max_problems' => 5,
    'review_max_output_tokens' => (int) env('INFOGRAPHIC_REVIEW_MAX_OUTPUT_TOKENS', 4_000),

    // The brief (the text step). The cap leaves room for a thinking model's reasoning.
    'brief_max_output_tokens' => (int) env('INFOGRAPHIC_BRIEF_MAX_OUTPUT_TOKENS', 16_000),
    // The model is asked for the limits below but cannot count characters (prod, 2026-11-19: a text of 122 and one of 128 against 120 failed a good brief twice).
    // D-054: the *_max values are TARGETS (the prompt says them). The checker refuses only beyond the HARD maximums. Nothing is cut: room for mistake.
    // A missing hard maximum falls back to the target x 2.5, never to refusing everything.
    'title_max' => 80, 'heading_max' => 40, 'figure_max' => 16, 'text_max' => 120,
    'title_hard_max' => 150, 'heading_hard_max' => 80, 'figure_hard_max' => 40, 'text_hard_max' => 300,
];
