<?php

return [

    // Limits checked at upload, before any job runs.
    'max_file_kb' => (int) env('SOURCE_MAX_FILE_KB', 20_480),
    'max_pdf_pages' => (int) env('SOURCE_MAX_PDF_PAGES', 300),
    // A page with fewer characters than this counts as having no text layer (a scan).
    'min_chars_per_page' => 20,

    // Web fetch (platform plan 1.9): public addresses only, ports 80/443, re-checked on every redirect.
    'web' => [
        'max_redirects' => 3,
        'timeout' => 10,
        'max_bytes' => 5 * 1024 * 1024,
        'allowed_ports' => [80, 443],
        'content_types' => ['text/html', 'text/plain', 'application/xhtml+xml'],
        // A fetched page with fewer readable characters than this (after cleaning) is not a source: it is almost always a page that needs JavaScript (D-060).
        // The source fails with a plain message instead of being stored as a near-empty ready source (D-038).
        'min_chars' => (int) env('SOURCE_MIN_WEB_CHARS', 200),
    ],

    // How much of a source is sent for its summary (characters).
    'summary_input_chars' => 24_000,

];
