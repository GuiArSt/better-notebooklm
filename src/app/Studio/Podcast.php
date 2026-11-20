<?php

namespace App\Studio;

use App\Ai\Language;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/** What an audio overview's Customize options mean (D-039): its format, and from that and the length, the limits of its script. All of it from config/podcast.php. */
class Podcast
{
    public const PERSONA_REFUSAL = 'A host persona is part of the Pro plan.';

    /** @return list<string> */
    public static function formats(): array
    {
        return array_keys(config('podcast.formats'));
    }

    /** @return list<string> */
    public static function lengths(): array
    {
        return array_keys(config('podcast.lengths'));
    }

    /** @param  array<string, mixed>  $options  the item's options */
    public static function format(array $options): string
    {
        return (string) ($options['format'] ?? config('podcast.default_format'));
    }

    /**
     * The script's limits: the chosen length, or the format's own start (Brief is short, the others default).
     *
     * @param  array<string, mixed>  $options
     * @return array{turns: int, chars: int}
     */
    public static function limits(array $options): array
    {
        $length = (string) ($options['length'] ?? (self::format($options) === 'brief' ? config('podcast.brief_length') : 'default'));

        return config("podcast.lengths.$length");
    }

    /** @param  array<string, mixed>  $options */
    public static function formatText(array $options): string
    {
        return trim(File::get(resource_path('prompts/formats/'.self::format($options).'.md')));
    }

    /** @return list<string> */
    public static function personas(): array
    {
        return array_keys(config('podcast.personas'));
    }

    /** Who may choose a persona: whoever hears ElevenLabs (pro, owner, reviewer), by the rule the voice routing uses. */
    public static function allowedFor(User $user): bool
    {
        return $user->effectiveTier() === 'pro' || in_array($user->getAttribute('role'), (array) config('elevenlabs.roles'), true);
    }

    /**
     * A persona is refused for anyone who may not choose one: at create, at estimate and at "Make it again" (a lapsed pro keeps no persona).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ValidationException
     */
    public static function requirePersonaAccess(User $user, array $options): void
    {
        if (self::persona($options) !== null && ! self::allowedFor($user)) {
            throw ValidationException::withMessages(['options.persona' => self::PERSONA_REFUSAL]);
        }
    }

    /** @param  array<string, mixed>  $options */
    public static function persona(array $options): ?string
    {
        return isset($options['persona']) ? (string) $options['persona'] : null;
    }

    /** The persona's bible and the guardrails, for the script prompt; empty without a persona. @param  array<string, mixed>  $options */
    public static function personaText(array $options): string
    {
        $persona = self::persona($options);
        if ($persona === null) {
            return '';
        }

        return trim(File::get(resource_path("prompts/personas/$persona.md")))."\n\n".trim(File::get(resource_path('prompts/personas/guardrails.md')));
    }

    /**
     * The two hosts' voices, never two of one gender (the owner, release 3: two women are hard to tell apart). Host B is the persona, or the app's male
     * voice; host A takes the voice of the other gender from the provider's pool. A persona's gender is config/podcast.php, its ElevenLabs voice
     * config/elevenlabs.php; on Mistral (no persona voices) B takes the pool's voice of the persona's gender. A language may have its own pool
     * (config/podcast.php host_voices.<provider>.<code>); the default pool is config/elevenlabs.php voices (female a, male b) and config/models.php voices.
     *
     * @param  'elevenlabs'|'mistral'  $provider
     * @param  array<string, mixed>  $settings  the item's settings: language and options
     * @return array{A: array{voice: string, gender: string}, B: array{voice: string, gender: string}}
     */
    public static function hosts(string $provider, array $settings): array
    {
        $options = (array) ($settings['options'] ?? []);
        $pool = self::pool($provider, (string) ($settings['language'] ?? 'auto'));
        $persona = self::persona($options);

        $genderB = $persona === null ? 'male' : (string) config("podcast.personas.$persona.gender");
        $genderA = $genderB === 'male' ? 'female' : 'male';
        $voiceB = $persona !== null && $provider === 'elevenlabs' ? (string) config("elevenlabs.persona_voices.$persona") : $pool[$genderB];

        if ($voiceB === '' || $pool[$genderA] === $voiceB) {
            throw new \RuntimeException("The hosts' voices for $provider are not two different voices.");
        }

        return ['A' => ['voice' => $pool[$genderA], 'gender' => $genderA], 'B' => ['voice' => $voiceB, 'gender' => $genderB]];
    }

    /** Voice ids by speaker letter. @param  array<string, mixed>  $settings @return array{A: string, B: string} */
    public static function voices(string $provider, array $settings): array
    {
        $hosts = self::hosts($provider, $settings);

        return ['A' => $hosts['A']['voice'], 'B' => $hosts['B']['voice']];
    }

    /** The speech block an item's settings record: who speaks, with which model and voices. @param  array<string, mixed>  $settings */
    public static function speech(string $provider, array $settings): array
    {
        $own = $provider === 'elevenlabs';

        return [
            'provider' => (string) ($own ? 'elevenlabs' : config('models.speech.provider')),
            'model' => (string) config($own ? 'elevenlabs.model' : 'models.speech.model'),
            'voices' => self::voices($provider, $settings),
        ];
    }

    /**
     * Gender => voice id for a provider and language: its default pool with the language's own voices on top. Fails loudly when a gender is missing.
     *
     * @return array{female: string, male: string}
     */
    public static function pool(string $provider, string $language): array
    {
        $key = $provider === 'elevenlabs' ? 'elevenlabs.voices' : 'models.voices';
        $pool = ['female' => (string) config("$key.a"), 'male' => (string) config("$key.b")]; // a is the female host, b the male one, by convention in both files
        $own = array_key_exists($language, Language::NAMES) ? (array) config("podcast.host_voices.$provider.$language", []) : [];
        $pool = array_filter($own) + $pool;

        foreach (['female', 'male'] as $gender) {
            if (($pool[$gender] ?? '') === '') {
                throw new \RuntimeException("No $gender host voice for $provider in $language.");
            }
        }

        return $pool;
    }

    /**
     * Keep the audio tags of the allowed list, at most one per turn; strip every other [bracket] before speech (a tag a voice does not know is read aloud).
     *
     * @param  list<string>  $allowed  without brackets; empty strips all
     */
    public static function stripTags(string $text, array $allowed): string
    {
        $kept = 0;
        $text = preg_replace_callback('/\s*\[([^\]]*)\]/u', function (array $m) use ($allowed, &$kept) {
            return in_array(trim($m[1]), $allowed, true) && $kept++ === 0 ? $m[0] : '';
        }, $text);

        return trim((string) preg_replace('/\s{2,}/u', ' ', (string) $text));
    }

    /**
     * What "How this was made" shows, from config, written into the item when it is made (what was heard stays true if the config changes).
     *
     * @param  array<string, mixed>  $options
     * @return array{format: string, persona: array{name: string, role: string}|null}
     */
    public static function labels(array $options): array
    {
        $persona = self::persona($options);

        return [
            'format' => (string) config('podcast.formats.'.self::format($options)),
            'persona' => $persona === null ? null : array_intersect_key(config("podcast.personas.$persona"), ['name' => 1, 'role' => 1]),
        ];
    }
}
