<?php

namespace App\Studio;

use App\Ai\Language;
use App\Models\Notebook;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the Customize step may send (D-035), and the one place that validates it: the language, the sources, a focus (plain text, at most 500
 * characters) and ONE option of the kind (audio length and format; quiz count and difficulty; slide count). Used by "generate" and by "estimate".
 */
class StudioOptions
{
    public const FOCUS_MAX = 500;

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public static function difficultyText(string $difficulty): string
    {
        return match ($difficulty) {
            'easy' => 'easy: plain recall of facts the passages state, with clearly wrong alternatives',
            'hard' => 'hard: questions that need two passages together or a careful reading, with plausible wrong alternatives',
            default => 'medium: a mix of recall and simple reasoning, with plausible wrong alternatives',
        };
    }

    /** @return array<string, mixed> the validation rules for a kind's request */
    public static function rules(string $kind): array
    {
        $rules = [
            'language' => ['nullable', 'string', Rule::in(['auto', ...array_keys(Language::NAMES)])],
            'sources' => ['nullable', 'array', 'min:1', 'max:50'],
            'sources.*' => ['integer', 'distinct'],
            'focus' => ['nullable', 'string', 'max:'.self::FOCUS_MAX],
            'options' => ['nullable', 'array'],
        ];

        // Only the options that belong to the kind; any other key under options is refused (validated below).
        $own = match ($kind) {
            'audio' => ['length' => ['nullable', Rule::in(Podcast::lengths())], 'format' => ['nullable', Rule::in(Podcast::formats())], 'persona' => ['nullable', Rule::in(Podcast::personas())]],
            'quiz' => [
                'questions' => ['nullable', 'integer', 'between:'.config('studio.quiz_min_questions').','.config('studio.quiz_max_questions')],
                'difficulty' => ['nullable', Rule::in(self::DIFFICULTIES)],
            ],
            'infographic' => InfographicOptions::rules(),
            'slides' => ['slides' => ['nullable', 'integer', 'between:'.SlideContract::MIN_SLIDES.','.SlideContract::MAX_SLIDES]],
            default => [],
        };
        foreach ($own as $key => $rule) {
            $rules["options.$key"] = $rule;
        }

        return $rules;
    }

    /**
     * Turn validated input into the part of the settings that records the choices. Sources must be this notebook's and ready.
     *
     * @param  array<string, mixed>  $data  the validated request
     * @param  array<string, mixed>  $rawOptions  the options as sent (to refuse keys that do not belong to the kind)
     * @return array{language: string, sources: list<array{id: int, title: string}>, focus: string|null, options: array<string, mixed>}
     */
    public static function normalise(Notebook $notebook, string $kind, array $data, array $rawOptions = []): array
    {
        $allowed = array_keys(array_filter(self::rules($kind), fn ($v, $k) => str_starts_with($k, 'options.'), ARRAY_FILTER_USE_BOTH));
        // validate() keeps only the rules' keys, so what the request really sent is checked here: an option of another kind is refused.
        $extra = array_diff(array_keys($rawOptions), array_map(fn ($k) => substr($k, 8), $allowed));
        if ($extra !== []) {
            throw ValidationException::withMessages(['options' => 'The option "'.reset($extra).'" does not belong to this kind.']);
        }

        Podcast::requirePersonaAccess($notebook->user, $rawOptions);
        if ($kind === 'infographic') {
            InfographicOptions::require($notebook->user);
            InfographicOptions::imageCost((array) ($data['options'] ?? []));
        }

        $ready = $notebook->sources()->where('status', 'ready');
        if (! empty($data['sources'])) {
            $chosen = (clone $ready)->whereIn('id', $data['sources'])->orderBy('id')->get(['id', 'title']);
            if ($chosen->count() !== count($data['sources'])) {
                throw ValidationException::withMessages(['sources' => 'Choose sources of this notebook that are ready.']);
            }
        } else {
            $chosen = (clone $ready)->where('checked', true)->orderBy('id')->get(['id', 'title']);
            if ($chosen->isEmpty()) {
                throw ValidationException::withMessages(['kind' => 'Add a source and keep it checked first.']);
            }
        }

        $focus = isset($data['focus']) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $data['focus']))) : '';
        $options = array_filter((array) ($data['options'] ?? []), fn ($v) => $v !== null && $v !== '');

        return [
            'language' => (string) ($data['language'] ?? 'auto'),
            'sources' => $chosen->map(fn ($s) => ['id' => $s->id, 'title' => $s->title])->all(),
            'focus' => $focus === '' ? null : $focus,
            'options' => $options,
        ];
    }
}
