<?php

namespace App\Ai;

/** A model call in flight: its hold on the ledger and its trace. */
final class OpenCall
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly TracedCall $traced,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $kind,
        public readonly string $keyOwner,
        public readonly ?int $notebookId,
        public readonly ?PromptVersion $prompt,
    ) {}
}
