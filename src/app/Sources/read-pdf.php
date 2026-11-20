<?php

use App\Sources\PdfExtractor;

// Child process of Parsers::pdf (D-040): php -d memory_limit=… read-pdf.php <pdf> <result.json> <max pages> <min chars per page>
// It has its own memory limit. If a PDF inflates past it, PHP stops THIS process and writes no result; the parent says so and ends the
// source as failed. It never touches the database or the app.
// If the container runs short of memory, the kernel must stop THIS process, never the worker that started it (raising one's own score is
// allowed without privileges; where /proc is not there, as on a Mac, it quietly does nothing).
@file_put_contents('/proc/self/oom_score_adj', '1000');

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $pdf, $out, $maxPages, $minChars] = $argv + [null, null, null, 300, 20];
$result = PdfExtractor::extract((string) $pdf, (int) $maxPages, (int) $minChars);
file_put_contents((string) $out, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
