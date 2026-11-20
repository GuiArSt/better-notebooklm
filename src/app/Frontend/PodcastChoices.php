<?php

namespace App\Frontend;

use App\Models\User;
use App\Studio\Podcast;

/** What the Customize step offers for an audio overview, read from config/podcast.php: the formats for all, the personas only for whoever may choose one, by the rule that also gives a 422 (D-039). */
class PodcastChoices
{
    /** @return array{brief_length: string, formats: list<array{value: string, label: string}>, default_format: string, personas: list<array{value: string, name: string, role: string, line: string}>} */
    public function for(User $user): array
    {
        return [
            'formats' => collect((array) config('podcast.formats'))->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'brief_length' => (string) config('podcast.brief_length'),
            'default_format' => (string) config('podcast.default_format'),
            'personas' => Podcast::allowedFor($user)
                ? collect((array) config('podcast.personas'))->map(fn (array $p, string $value) => ['value' => $value, ...$p])->values()->all()
                : [],
        ];
    }
}
