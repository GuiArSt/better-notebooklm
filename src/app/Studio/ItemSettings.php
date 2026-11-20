<?php

namespace App\Studio;

use App\Models\Notebook;
use App\Models\User;

/** How a studio item is made, written down when it is created: what the interface shows under "How this was made", and what "Make it again" repeats. */
class ItemSettings
{
    public function __construct(private StudioGenerator $generator) {}

    /**
     * @param  array{language?: string, sources?: list<array{id: int, title: string}>, focus?: string|null, options?: array<string, mixed>}  $choices  from StudioOptions::normalise
     * @return array<string, mixed>
     */
    public function make(User $user, Notebook $notebook, string $kind, array $choices = []): array
    {
        $tier = $user->effectiveTier();
        $family = $kind === 'slides' ? 'slides' : 'chat';
        $text = config("models.$family.$tier") ?? config('models.chat.free');

        $settings = [
            'kind' => $kind,
            'sources' => $choices['sources'] ?? $notebook->sources()->where('checked', true)->where('status', 'ready')->orderBy('id')->get(['id', 'title'])
                ->map(fn ($s) => ['id' => $s->id, 'title' => $s->title])->all(),
            'language' => $choices['language'] ?? 'auto', // auto: the sources' own language, named in the prompt (App\Ai\Language)
            'focus' => $choices['focus'] ?? null,
            'options' => $choices['options'] ?? [],
            'instructions' => null, // free-form instructions are the focus box
            'tier' => $tier,
            'models' => ['text' => ['provider' => $text['provider'], 'model' => $text['model']]],
        ];

        if ($kind === 'audio') {
            $settings['models']['speech'] = Podcast::speech($this->generator->usesElevenLabs($user) ? 'elevenlabs' : 'mistral', $settings);
            $settings['podcast'] = Podcast::labels((array) $settings['options']);
        }

        return $settings;
    }
}
