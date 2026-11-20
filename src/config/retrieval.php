<?php

return [

    // At or below this many tokens (estimated) the checked sources go to the model whole; above it, the best passages.
    'whole_budget_tokens' => (int) env('RETRIEVAL_WHOLE_BUDGET_TOKENS', 60_000),

    'top_k' => (int) env('RETRIEVAL_TOP_K', 8),

    'chunk_chars' => (int) env('CHUNK_CHARS', 900),

    // A source (pasted, a file, a web page or a PDF's text) may be at most this long, in characters (D-040). Proved on dev with a 13 MB, 158-page
    // book (590,540 characters): about 1 minute and $0.02 to read, 141 MB of PHP memory for its parse. A 300-page PDF is about 1.1 million.
    'max_source_chars' => (int) env('MAX_SOURCE_CHARS', 1_200_000),

    // A PDF is read in a child process with this much memory (MB) and this long (seconds): one that inflates past it ends that source as failed, never the
    // worker (D-040). The 13 MB, 158-page book needs 129 MB; a dense 45 MB PDF about 270 MB. The demo worker container is 512 MB and holds the worker (~100 MB)
    // too; the prod default worker is 384 MB, so set PDF_MEMORY_MB to fit the container you run in. If the container runs out first, the child is the one
    // the kernel stops (read-pdf.php raises its own oom score).
    'pdf_memory_mb' => (int) env('PDF_MEMORY_MB', 320),
    'pdf_timeout_seconds' => (int) env('PDF_TIMEOUT_SECONDS', 600),

    // Above this many characters a source is "large": the interface warns, with its page count and what reading it will cost.
    'large_source_chars' => (int) env('LARGE_SOURCE_CHARS', 200_000),

];
