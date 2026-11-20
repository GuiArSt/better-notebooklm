<?php

namespace App\Frontend;

use App\Models\User;
use App\Studio\InfographicOptions;

/**
 * What the Customize step offers for an infographic (D-045), from config/infographics.php: the tile is live only where the switch is on and
 * this user may make one (pro, reviewer, owner); the choices carry the server's own labels.
 */
class InfographicChoices
{
    /** @return array{available: bool, orientations: list<array{value: string, label: string}>, styles: list<array{value: string, label: string}>, details: list<array{value: string, label: string}>, default_orientation: string, default_style: string, default_detail: string} */
    public function for(User $user): array
    {
        $words = fn (array $keys) => collect($keys)->map(fn (string $key) => ['value' => $key, 'label' => ucfirst($key)])->values()->all();

        return [
            'available' => (bool) config('infographics.enabled') && InfographicOptions::allowedFor($user),
            'orientations' => $words(array_keys((array) config('infographics.orientations'))),
            'styles' => collect((array) config('infographics.styles'))->map(fn (array $s, string $key) => ['value' => $key, 'label' => (string) $s['label']])->values()->all(),
            'details' => $words(array_keys((array) config('infographics.details'))),
            'default_orientation' => (string) config('infographics.default_orientation'),
            'default_style' => (string) config('infographics.default_style'),
            'default_detail' => (string) config('infographics.default_detail'),
        ];
    }
}
