<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One HTTP client for ElevenLabs Text to Dialogue (there is no official laravel/ai provider). It is only ever called from
 * Router::dialogue, which is where the cap, the ledger and the trace happen. It never logs or returns the key.
 */
class ElevenLabs
{
    public function configured(): bool
    {
        return filled(config('elevenlabs.key'));
    }

    /**
     * @param  list<array{text: string, voice_id: string}>  $inputs  one entry per turn, in order
     * @return array{audio: string, credits: int|null} the MP3 bytes, and the credits ElevenLabs says it used (its `character-cost` header)
     */
    public function dialogue(array $inputs, string $model): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('ElevenLabs is not configured.');
        }

        $response = Http::withHeaders(['xi-api-key' => (string) config('elevenlabs.key'), 'Accept' => 'audio/mpeg'])
            ->timeout((int) config('elevenlabs.timeout'))
            ->withQueryParameters(['output_format' => (string) config('elevenlabs.output_format')])
            ->post(rtrim((string) config('elevenlabs.base_url'), '/').'/v1/text-to-dialogue', ['inputs' => $inputs, 'model_id' => $model]);

        if (! $response->successful()) {
            // ElevenLabs names the problem in `detail.status` (for example invalid_api_key): a short lowercase code, safe to log, never the key.
            $code = $response->json('detail.status');
            $code = is_string($code) && preg_match('/^[a-z0-9_]{1,60}$/', $code) ? " ($code)" : '';

            throw new RuntimeException('ElevenLabs answered with status '.$response->status().$code.'.');
        }
        if ($response->body() === '') {
            throw new RuntimeException('ElevenLabs returned no audio.');
        }

        $credits = $response->header('character-cost');

        return ['audio' => $response->body(), 'credits' => is_numeric($credits) ? (int) $credits : null];
    }
}
