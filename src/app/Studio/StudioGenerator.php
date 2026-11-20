<?php

namespace App\Studio;

use App\Ai\ElevenLabs;
use App\Ai\Exceptions\CapExceeded;
use App\Ai\Exceptions\UserFacingException;
use App\Ai\Language;
use App\Ai\Ledger;
use App\Ai\ModelJson;
use App\Ai\Router;
use App\Ai\SafeLog;
use App\Chat\CitationValidator;
use App\Chat\Retriever;
use App\Chat\SourceBlock;
use App\Models\Source;
use App\Models\StudioItem;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/** Builds a studio item. Every model call goes through the Router, so each step is capped, traced and in the ledger. */
class StudioGenerator
{
    public function __construct(
        private Router $router,
        private Retriever $retriever,
        private CitationValidator $validator,
        private AudioJoiner $joiner,
        private ElevenLabs $elevenLabs,
        private Ledger $ledger,
    ) {}

    public function generate(StudioItem $item): void
    {
        $item->loadMissing('notebook');
        $user = User::findOrFail($item->user_id);

        match ($item->kind) {
            'audio' => $this->audio($item, $user),
            'mindmap' => $this->mindmap($item, $user),
            'quiz' => $this->quiz($item, $user),
            'slides' => $this->slides($item, $user),
            'infographic' => app(InfographicGenerator::class)->generate($item, $user),
            default => $this->report($item, $user),
        };
    }

    /**
     * The language to write in, named: the one the user chose for this item (settings.language), otherwise the sources' own. Never inferred
     * from the browser or the app locale (D-035).
     */
    public function language(StudioItem $item, string $sourcesBlock): string
    {
        $chosen = (string) ($item->settings['language'] ?? 'auto');
        if ($chosen !== '' && $chosen !== 'auto') {
            return Language::forPrompt($chosen, $sourcesBlock);
        }

        // Automatic: the majority language of the chosen sources (stored on each source), by name; the passages' own text only as a fallback.
        $ids = $this->chosenSourceIds($item);
        $codes = Source::query()->where('notebook_id', $item->notebook_id)->where('status', 'ready')
            ->when($ids === null, fn ($q) => $q->where('checked', true), fn ($q) => $q->whereIn('id', $ids))->pluck('language')->all();

        return Language::name(Language::majority($codes)) ?? Language::forPrompt(null, $sourcesBlock);
    }

    /** What the reader asked to focus on, as <user_input from="focus"> (a topic, never an instruction: every prompt says so); '' when there is none. */
    public function focusPart(StudioItem $item): string
    {
        $focus = (string) ($item->settings['focus'] ?? '');

        return $focus === '' ? '' : "\n\n".SourceBlock::userInput('focus', $focus);
    }

    /** @return list<int>|null the sources chosen for this item, or null for the notebook's checked ones */
    private function chosenSourceIds(StudioItem $item): ?array
    {
        $ids = array_values(array_filter(array_map(fn ($s) => is_array($s) ? (int) ($s['id'] ?? 0) : 0, (array) ($item->settings['sources'] ?? []))));

        return $ids === [] ? null : $ids;
    }

    private function report(StudioItem $item, User $user): void
    {
        $item->progress('Reading the sources', 1, 3);
        [$sources, $shownIds] = $this->sourcesBlock($item, $user, $this->title($item->kind));

        $item->progress('Writing', 2, 3);
        $result = $this->router->text(
            $user, 'studio.report.'.$item->kind, ['language' => $this->language($item, $sources)], $sources.$this->focusPart($item).SourceBlock::closing('Write the '.$this->title($item->kind).'.'),
            'chat', $item->notebook_id, maxOutputTokens: (int) config('studio.report_max_output_tokens'),
        );

        // Never done on nothing, never done on half: an empty or cut report fails, and the item offers Retry.
        if (trim($result->text) === '' || $result->cutOff()) {
            SafeLog::warning('A report came back empty or cut off', ['item_id' => $item->id, 'kind' => $item->kind, 'finish' => $result->finishReason]);

            throw new UserFacingException($result->cutOff() ? 'The report hit its length limit and was cut short. Try again, or narrow it with a focus.' : 'The model returned an empty report. Try again.');
        }

        $item->progress('Checking the citations', 3, 3);
        $resolved = $this->validator->resolve($result->text, $item->notebook, $result->traceId, $shownIds);

        $item->update(['status' => 'done', 'step' => null, 'content' => trim($result->text), 'citations' => $resolved['citations']]);
    }

    private function mindmap(StudioItem $item, User $user): void
    {
        $item->progress('Reading the sources', 1, 3);
        [$sources, $shownIds] = $this->sourcesBlock($item, $user, 'the main topics and how they relate');

        $item->progress('Drawing the map', 2, 3);
        $maxNodes = (int) config('studio.mindmap_max_nodes');
        $language = $this->language($item, $sources);
        $result = $this->router->text(
            $user, 'studio.mindmap', ['max_nodes' => (string) $maxNodes, 'language' => $language], $sources.$this->focusPart($item).SourceBlock::closing('Build the mind map.'),
            'chat', $item->notebook_id, maxOutputTokens: (int) config('studio.mindmap_max_output_tokens'), schema: 'mindmap',
        );

        $item->progress('Checking the references', 3, 3);
        $data = app(ModelJson::class)->read($user, $result->text, 'The model did not return a usable mind map.', $item->notebook_id, schema: 'mindmap', maxOutputTokens: (int) config('studio.mindmap_max_output_tokens'));
        $maxDepth = (int) config('studio.mindmap_max_depth');
        $data = $this->repairLong($user, $item, 'studio.mindmap.repair', 'mindmap', (int) config('studio.mindmap_max_output_tokens'), 'The model did not return a usable mind map.', $language, $data, fn (array $d) => $this->mindmapLengthProblems($d, $maxNodes, $maxDepth));
        $tree = $this->parseMindmap($data, $shownIds, $maxNodes, $maxDepth);

        // Every passage the map points at, with its quote, so the interface can show it on hover (same shape as chat citations).
        $refs = implode(' ', array_map(fn ($id) => "[P$id]", $this->refsIn($tree['children'])));
        $resolved = $this->validator->resolve($refs, $item->notebook, $result->traceId, $shownIds);

        $item->update(['status' => 'done', 'step' => null, 'title' => $tree['title'], 'tree' => $tree, 'citations' => $resolved['citations']]);
    }

    private function slides(StudioItem $item, User $user): void
    {
        $item->progress('Reading the sources', 1, 4);
        [$sources, $shownIds] = $this->sourcesBlock($item, $user, 'the main story, the key facts and numbers, and a good quote');

        $item->progress('Designing the slides', 2, 4);
        $variables = [
            'min_slides' => (string) SlideContract::MIN_SLIDES, 'max_slides' => (string) SlideContract::MAX_SLIDES,
            'max_same' => (string) SlideContract::MAX_SAME_IN_A_ROW, 'min_distinct' => (string) SlideContract::MIN_DISTINCT_TEMPLATES,
            'language' => $this->language($item, $sources), 'slide_count' => 'Make exactly '.(int) ($item->settings['options']['slides'] ?? SlideContract::DEFAULT_SLIDES).' slides',
        ];
        $maxOut = (int) config('studio.slides_max_output_tokens');
        $result = $this->router->text($user, 'studio.slides', $variables, $sources.$this->focusPart($item).SourceBlock::closing('Make the slide deck.'), 'slides', $item->notebook_id, maxOutputTokens: $maxOut, schema: 'slides');

        [$deck, $errors, $trimmed] = $this->checkDeck($result->text);
        if ($errors !== []) {
            // One repair call, with the checker's own words. A second failure ends the item cleanly (both calls are in the ledger).
            $item->progress('Fixing what the checker refused', 3, 4);
            $repair = $this->router->text(
                $user, 'studio.slides.repair', $variables,
                SourceBlock::sourceData('deck', $result->text).SourceBlock::closing("The problems:\n- ".implode("\n- ", array_slice($errors, 0, 25))."\n\nWrite the corrected deck."),
                'slides', $item->notebook_id, maxOutputTokens: $maxOut, schema: 'slides',
            );
            [$deck, $errors, $trimmed] = $this->checkDeck($repair->text);
            $result = $repair;
            if ($errors !== []) {
                SafeLog::failure('Slide deck (refused twice by the checker)', new \RuntimeException, ['item_id' => $item->id, 'problems' => count($errors)]);
                throw new UserFacingException('The model did not return a usable slide deck.');
            }
        }

        $item->progress('Checking the citations', 4, 4);
        $deck = $this->cleanDeck($deck, $shownIds);
        $refs = implode(' ', array_map(fn ($ref) => "[$ref]", array_values(array_unique(array_merge([], ...array_column($deck['slides'], 'citations'))))));
        $resolved = $this->validator->resolve($refs, $item->notebook, $result->traceId, $shownIds);

        $settings = (array) $item->settings;
        if (($note = SlideTrim::note($trimmed)) !== null) {
            $settings['trimmed'] = $note;
        }
        $asked = (int) ($settings['options']['slides'] ?? SlideContract::DEFAULT_SLIDES);
        if (count($deck['slides']) !== $asked) {
            $settings['delivered'] = ['asked' => $asked, 'got' => count($deck['slides'])]; // record only: the deck is within 6 to 12 and ships
        }
        $item->update(['status' => 'done', 'step' => null, 'title' => $deck['title'], 'slides' => $deck['slides'], 'citations' => $resolved['citations'], 'settings' => $settings]);
    }

    /** @return array{0: mixed, 1: list<string>, 2: array<string, int>} the decoded deck (trimmed), the contract's problems with it, and what was trimmed */
    public function checkDeck(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $deck = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if ($deck === null) {
            return [null, ['The answer was not valid JSON. Answer with the JSON object only.'], SlideTrim::apply(null)[1]];
        }

        // Counts are structure: extra slides and list items are dropped first. Lengths are left to the model.
        [$deck, $trimmed] = SlideTrim::apply($deck);

        return [$deck, SlideContract::validate($deck), $trimmed];
    }

    /**
     * A valid deck with its text trimmed and its citations reduced to the passages the model was shown (an invented id is dropped).
     *
     * @param  array<string, mixed>  $deck
     * @param  list<int>  $shownIds
     * @return array{title: string, slides: list<array{template: string, fields: array<string, mixed>, citations: list<string>}>}
     */
    public function cleanDeck(array $deck, array $shownIds): array
    {
        $trim = function ($v) use (&$trim) {
            return is_array($v) ? array_map($trim, $v) : (is_string($v) ? trim($v) : $v);
        };

        $slides = [];
        foreach ($deck['slides'] as $slide) {
            // Citations stay "P12" strings, as the contract says (the viewer's chips read them so); only ids the model was shown survive.
            $ids = [];
            foreach ($slide['citations'] ?? [] as $ref) {
                if (in_array((int) substr($ref, 1), $shownIds, true)) {
                    $ids[] = $ref;
                }
            }
            $slides[] = ['template' => $slide['template'], 'fields' => $trim($slide['fields']), 'citations' => array_values(array_unique($ids))];
        }

        return ['title' => trim($deck['title']), 'slides' => $slides];
    }

    private function quiz(StudioItem $item, User $user): void
    {
        $item->progress('Reading the sources', 1, 3);
        [$sources, $shownIds] = $this->sourcesBlock($item, $user, 'the key facts and ideas worth testing');

        $item->progress('Writing the questions', 2, 3);
        $min = (int) config('studio.quiz_min_questions');
        $max = (int) config('studio.quiz_max_questions');
        $asked = (int) ($item->settings['options']['questions'] ?? $max); // the number the reader chose; the model is told exactly that many
        $difficulty = (string) ($item->settings['options']['difficulty'] ?? 'medium');
        $language = $this->language($item, $sources);
        $result = $this->router->text(
            $user, 'studio.quiz', ['min_questions' => (string) $asked, 'max_questions' => (string) $asked, 'difficulty' => StudioOptions::difficultyText($difficulty), 'language' => $language], $sources.$this->focusPart($item).SourceBlock::closing('Write the quiz: exactly '.$asked.' questions.'),
            'chat', $item->notebook_id, maxOutputTokens: (int) config('studio.quiz_max_output_tokens'), schema: 'quiz',
        );

        $item->progress('Checking the answers and citations', 3, 3);
        $data = app(ModelJson::class)->read($user, $result->text, 'The model did not return a usable quiz.', $item->notebook_id, schema: 'quiz', maxOutputTokens: (int) config('studio.quiz_max_output_tokens'));
        $data = $this->repairLong($user, $item, 'studio.quiz.repair', 'quiz', (int) config('studio.quiz_max_output_tokens'), 'The model did not return a usable quiz.', $language, $data, fn (array $d) => $this->quizLengthProblems($d, $asked));
        $quiz = $this->parseQuiz($data, $shownIds, $min, $asked);

        // Every passage an explanation cites, with its quote, in the same shape as chat citations (validated against what the model was shown).
        $refs = implode(' ', array_map(fn ($id) => "[P$id]", array_values(array_unique(array_merge(...array_column($quiz['questions'], 'refs'))))));
        $resolved = $this->validator->resolve($refs, $item->notebook, $result->traceId, $shownIds);

        // Asked for N, got fewer whole questions: say so in the settings (record only; the item still ships).
        $settings = (array) $item->settings;
        if (count($quiz['questions']) !== $asked) {
            $settings['delivered'] = ['asked' => $asked, 'got' => count($quiz['questions'])];
        }
        $item->update(['status' => 'done', 'step' => null, 'title' => $quiz['title'], 'quiz' => $quiz['questions'], 'citations' => $resolved['citations'], 'settings' => $settings]);
    }

    /**
     * D-054: a text over its hard maximum is not cut. It goes back to the model once, with the checker's own words (a ledger row, like every call),
     * and if it is still over the item ends with a plain message. An answer within the maximums is returned as it came.
     *
     * @param  array<mixed>  $data
     * @param  callable(array<mixed>): list<string>  $problems
     * @return array<mixed>
     */
    private function repairLong(User $user, StudioItem $item, string $prompt, string $schema, int $maxOut, string $fail, string $language, array $data, callable $problems): array
    {
        $found = $problems($data);
        if ($found === []) {
            return $data;
        }

        $repair = $this->router->text(
            $user, $prompt, ['language' => $language],
            SourceBlock::sourceData('answer', (string) json_encode($data, JSON_UNESCAPED_UNICODE)).SourceBlock::closing("The problems:\n- ".implode("\n- ", array_slice($found, 0, 25))."\n\nWrite the corrected JSON."),
            'chat', $item->notebook_id, maxOutputTokens: $maxOut, schema: $schema,
        );
        $data = app(ModelJson::class)->read($user, $repair->text, $fail, $item->notebook_id, schema: $schema, maxOutputTokens: $maxOut);
        if (($still = $problems($data)) !== []) {
            SafeLog::failure("$prompt (still over the hard maximum after the repair)", new \RuntimeException, ['item_id' => $item->id, 'problems' => count($still)]);

            throw new UserFacingException($fail);
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string> the fields over their hard maximum, the first $asked questions only (the rest are dropped anyway)
     */
    public function quizLengthProblems(array $data, int $asked): array
    {
        $hard = (array) config('studio.quiz_hard_max');
        $problems = [];
        $check = function (string $where, mixed $value, string $key) use ($hard, &$problems): void {
            $length = is_string($value) ? mb_strlen(trim(strip_tags($value))) : 0;
            if ($length > $hard[$key]) {
                $problems[] = "$where is $length characters; the limit is {$hard[$key]}. Shorten it.";
            }
        };

        $check('The title', $data['title'] ?? null, 'title');
        foreach (array_slice(array_values((array) ($data['questions'] ?? [])), 0, $asked) as $i => $q) {
            $n = $i + 1;
            $q = is_array($q) ? $q : [];
            $check("Question $n", $q['question'] ?? null, 'question');
            foreach (array_values((array) ($q['options'] ?? [])) as $j => $option) {
                $check("Question $n option ".($j + 1), $option, 'option');
            }
            $check("Question $n hint", $q['hint'] ?? null, 'hint');
            $check("Question $n explanation", $q['explanation'] ?? null, 'explanation');
        }

        return $problems;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string> the title and the labels (within the node and depth caps) over their hard maximum
     */
    public function mindmapLengthProblems(array $data, int $maxNodes, int $maxDepth): array
    {
        $hard = (array) config('studio.mindmap_hard_max');
        $problems = [];
        $count = 0;
        $length = fn (mixed $value): int => is_string($value) ? mb_strlen(trim(strip_tags($value))) : 0;

        if ($length($data['title'] ?? null) > $hard['title']) {
            $problems[] = 'The title is '.$length($data['title']).' characters; the limit is '.$hard['title'].'. Shorten it.';
        }
        $walk = function (mixed $nodes, int $depth, string $path) use (&$walk, &$count, &$problems, $hard, $length, $maxNodes, $maxDepth): void {
            foreach (array_values((array) $nodes) as $i => $node) {
                if (! is_array($node) || $length($node['label'] ?? null) === 0 || $count >= $maxNodes) {
                    continue;
                }
                $count++;
                $where = $path.($i + 1);
                if ($length($node['label']) > $hard['label']) {
                    $problems[] = "The label of node $where is ".$length($node['label'])." characters; the limit is {$hard['label']}. Shorten it.";
                }
                if ($depth < $maxDepth) {
                    $walk($node['children'] ?? [], $depth + 1, "$where.");
                }
            }
        };
        $walk($data['children'] ?? [], 1, '');

        return $problems;
    }

    /**
     * Turn the model's JSON into a safe quiz. A question is kept only if it is whole: a text, exactly four non-empty options, an answer
     * index 0 to 3, and at least one reference to a passage the model was shown. Nothing is repaired by guessing; fewer than the minimum
     * whole questions fails the item cleanly.
     *
     * @param  list<int>  $shownIds
     * @return array{title: string, questions: list<array{question: string, options: list<string>, answer: int, hint: string, explanation: string, refs: list<int>}>}
     */
    public function parseQuiz(string|array $text, array $shownIds, int $min, int $max): array
    {
        $data = is_array($text) ? $text : ModelJson::parse($text)[0]; // the caller reads it through ModelJson (one repair); a string is read here, no repair
        if (! is_array($data) || ! is_array($data['questions'] ?? null)) {
            throw new UserFacingException('The model did not return a usable quiz.');
        }

        $clean = fn (mixed $v): string => is_string($v) ? trim(strip_tags($v)) : ''; // never cut: a text over its hard maximum was sent back before this point (D-054)
        $questions = [];
        foreach ($data['questions'] as $q) {
            if (! is_array($q) || count($questions) >= $max) {
                continue;
            }
            $options = is_array($q['options'] ?? null) ? array_values(array_map(fn ($o) => $clean($o), $q['options'])) : [];
            $answer = $q['answer'] ?? null;
            if (is_string($answer) && preg_match('/^[A-Da-d]$/', trim($answer))) {
                $answer = ord(strtoupper(trim($answer))) - ord('A');
            }
            $refs = [];
            foreach ((array) ($q['refs'] ?? []) as $ref) {
                if (is_string($ref) && preg_match('/^P(\d+)$/', $ref, $m) && in_array((int) $m[1], $shownIds, true)) {
                    $refs[] = (int) $m[1];
                }
            }
            $question = $clean($q['question'] ?? null);

            if ($question === '' || count($options) !== 4 || in_array('', $options, true) || count(array_unique($options)) !== 4
                || ! is_int($answer) || $answer < 0 || $answer > 3 || $refs === []) {
                continue;
            }

            $questions[] = [
                'question' => $question, 'options' => $options, 'answer' => $answer,
                'hint' => $clean($q['hint'] ?? null), 'explanation' => $clean($q['explanation'] ?? null),
                'refs' => array_values(array_unique($refs)),
            ];
        }

        if (count($questions) < $min) {
            throw new UserFacingException('The model did not return a usable quiz.');
        }

        return ['title' => $clean($data['title'] ?? null) ?: 'Quiz', 'questions' => $questions];
    }

    /**
     * Turn the model's JSON into a safe tree: strings trimmed, depth and node count capped, references kept only if the
     * model was shown that passage. Anything that does not fit is dropped, never repaired by guessing.
     *
     * @param  list<int>  $shownIds
     * @return array{title: string, children: list<array<string, mixed>>}
     */
    public function parseMindmap(string|array $text, array $shownIds, int $maxNodes, int $maxDepth): array
    {
        $data = is_array($text) ? $text : ModelJson::parse($text)[0]; // the caller reads it through ModelJson (one repair); a string is read here, no repair
        if (! is_array($data) || ! is_array($data['children'] ?? null)) {
            throw new UserFacingException('The model did not return a usable mind map.');
        }

        $count = 0;
        $clean = function (array $nodes, int $depth) use (&$clean, &$count, $maxNodes, $maxDepth, $shownIds): array {
            $out = [];
            foreach ($nodes as $node) {
                $label = is_array($node) && is_string($node['label'] ?? null) ? trim(strip_tags($node['label'])) : '';
                if ($label === '' || $count >= $maxNodes) {
                    continue;
                }
                $count++;
                $refs = [];
                foreach ((array) ($node['refs'] ?? []) as $ref) {
                    if (is_string($ref) && preg_match('/^P(\d+)$/', $ref, $m) && in_array((int) $m[1], $shownIds, true)) {
                        $refs[] = (int) $m[1];
                    }
                }
                $children = ($depth < $maxDepth && is_array($node['children'] ?? null)) ? $clean($node['children'], $depth + 1) : [];
                $out[] = ['label' => $label, 'refs' => array_values(array_unique($refs)), 'children' => $children];
            }

            return $out;
        };

        $children = $clean($data['children'], 1);
        if ($children === []) {
            throw new UserFacingException('The mind map was empty.');
        }

        return ['title' => trim(strip_tags((string) ($data['title'] ?? ''))) ?: 'Mind map', 'children' => $children];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<int>
     */
    private function refsIn(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            array_push($ids, ...$node['refs'], ...$this->refsIn($node['children']));
        }

        return array_values(array_unique($ids));
    }

    private function audio(StudioItem $item, User $user): void
    {
        $item->progress('Writing the script', 1, 3);
        [$sources] = $this->sourcesBlock($item, $user, 'the main points of the sources');

        $options = (array) ($item->settings['options'] ?? []);
        if (Podcast::persona($options) !== null && ! Podcast::allowedFor($user)) {
            throw new UserFacingException(Podcast::PERSONA_REFUSAL); // access can lapse between asking and generating
        }
        ['turns' => $maxTurns, 'chars' => $maxChars] = Podcast::limits($options);

        // The whole episode (script and speech, at their worst case) is reserved before the first call. An episode that
        // cannot be afforded is refused here, before anything is spent, instead of failing after the script was paid for.
        $this->ledger->beginEpisode($user, $this->router->estimateText($user, $sources, (int) config('studio.audio_script_max_output_tokens')) + $this->speechEstimate($user, $maxChars));

        try {
            $result = $this->router->text(
                $user, 'studio.audio.script', ['max_turns' => (string) $maxTurns, 'max_chars' => (string) $maxChars, 'language' => $this->language($item, $sources), 'format' => Podcast::formatText($options), 'persona' => Podcast::personaText($options)],
                $sources.$this->focusPart($item).SourceBlock::closing('Write the script.'), 'chat', $item->notebook_id, maxOutputTokens: (int) config('studio.audio_script_max_output_tokens'), schema: 'podcast_script',
            );

            $data = app(ModelJson::class)->read($user, $result->text, 'The model did not return a usable script.', $item->notebook_id, schema: 'podcast_script', maxOutputTokens: (int) config('studio.audio_script_max_output_tokens'));
            ['title' => $title, 'turns' => $turns] = $this->parseScript($data, $maxTurns, $maxChars, Podcast::persona($options) === null ? [] : (array) config('elevenlabs.tags'));
            $total = count($turns) + 2;
            $item->update(['title' => $title, 'script' => $turns]);

            $parts = $this->voice($item, $user, $turns, $total);
        } finally {
            // Whatever happened, what was actually spent is in the ledger and the episode's own reservation is gone.
            $this->ledger->endEpisode();
        }

        $item->progress('Joining the audio', $total, $total);
        $path = "studio/{$item->notebook_id}/{$item->id}.mp3";
        Storage::disk('local')->put($path, $this->joiner->join($parts));

        $settings = (array) $item->settings;
        if (($seconds = $this->duration(Storage::disk('local')->path($path))) !== null) {
            $settings['duration_seconds'] = $seconds;
        }
        $item->update(['status' => 'done', 'step' => null, 'file_path' => $path, 'settings' => $settings]);
    }

    /** The length of the MP3 in seconds, when ffprobe (in the media image) can tell; otherwise null. */
    private function duration(string $file): ?int
    {
        try {
            $result = Process::timeout(20)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $file]);

            if ($result->successful() && is_numeric(trim($result->output()))) {
                return (int) round((float) trim($result->output()));
            }
            SafeLog::warning('ffprobe could not read the audio length', ['exit_code' => $result->exitCode()]);

            return null;
        } catch (\Throwable $e) {
            SafeLog::failure('Audio length (ffprobe)', $e);

            return null;
        }
    }

    /** The most the speech of an episode can cost, for the voice this user would hear. */
    public function speechEstimate(User $user, int $characters): int
    {
        $model = $this->usesElevenLabs($user) ? (string) config('elevenlabs.model') : (string) config('models.speech.model');
        $rate = (int) (config("pricing.speech.$model") ?? config('pricing.speech_default'));

        return (int) ceil($characters * $rate / 1_000_000);
    }

    /**
     * The voice. Reviewers, the owner and pro users hear ElevenLabs (Text to Dialogue, in stretches of at most
     * max_chars_per_request); everyone else, and anyone if ElevenLabs fails, hears Mistral turn by turn.
     *
     * @param  list<array{speaker: string, text: string}>  $turns
     * @return list<string> MP3 bytes, in order
     */
    private function voice(StudioItem $item, User $user, array $turns, int $total): array
    {
        if ($this->usesElevenLabs($user)) {
            try {
                $parts = [];
                $voices = Podcast::voices('elevenlabs', (array) $item->settings); // one female and one male voice, whatever the persona
                foreach ($this->groups($turns) as $n => $group) {
                    $item->progress('Recording voices', $n + 2, $total);
                    $withVoices = array_map(fn (array $turn) => $turn + ['voice_id' => $voices[$turn['speaker']]], $group);
                    $parts[] = $this->router->dialogue($user, $withVoices, $item->notebook_id)->audio;
                }

                return $parts;
            } catch (CapExceeded $e) {
                throw $e;
            } catch (\Throwable $e) {
                SafeLog::failure('ElevenLabs podcast voice (falling back to Mistral)', $e, ['item_id' => $item->id]);
                // Record what is really heard: the whole episode is voiced by Mistral from here (the voices must not change halfway).
                $settings = (array) $item->settings;
                $settings['models']['speech'] = Podcast::speech('mistral', $settings);
                $settings['voice_fallback'] = get_class($e);
                $item->update(['settings' => $settings]);
            }
        }

        $parts = [];
        $voices = Podcast::voices('mistral', (array) $item->settings);
        foreach ($turns as $i => $turn) {
            $item->progress('Recording voices', $i + 2, $total);
            $voice = $voices[$turn['speaker']];
            // Mistral's voice would read an audio tag aloud: it hears none.
            $parts[] = $this->router->speech($user, Podcast::stripTags($turn['text'], []), $voice, $item->notebook_id)->audio;
        }

        return $parts;
    }

    public function usesElevenLabs(User $user): bool
    {
        return $this->elevenLabs->configured() && Podcast::allowedFor($user);
    }

    /**
     * @param  list<array{speaker: string, text: string}>  $turns
     * @return list<list<array{speaker: string, text: string}>>
     */
    public function groups(array $turns): array
    {
        $max = (int) config('elevenlabs.max_chars_per_request');
        $groups = [];
        $current = [];
        $chars = 0;
        foreach ($turns as $turn) {
            $len = mb_strlen($turn['text']);
            if ($current !== [] && $chars + $len > $max) {
                $groups[] = $current;
                [$current, $chars] = [[], 0];
            }
            $current[] = $turn;
            $chars += $len;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /** @return array{0: string, 1: list<int>} the delimited passages for the user turn, and the ids shown */
    public function sourcesBlock(StudioItem $item, User $user, string $query): array
    {
        $focus = (string) ($item->settings['focus'] ?? '');
        $passages = $this->retriever->context($user, $item->notebook, $focus !== '' ? $focus : $query, limit: (int) config('studio.passages_per_item'), onlySourceIds: $this->chosenSourceIds($item));
        if ($passages->isEmpty()) {
            throw new UserFacingException('There are no checked, ready sources to work from.');
        }

        return [SourceBlock::passages($passages), array_values(array_map(intval(...), $passages->pluck('id')->all()))];
    }

    /**
     * @param  list<string>  $tags  the audio tags a turn may keep (a persona's episode); empty strips every [bracket]
     * @return array{title: string, turns: list<array{speaker: string, text: string}>}
     */
    public function parseScript(string|array $text, int $maxTurns, int $maxChars, array $tags = []): array
    {
        $data = is_array($text) ? $text : ModelJson::parse($text)[0]; // the caller reads it through ModelJson (one repair); a string is read here, no repair
        if (! is_array($data) || ! is_array($data['turns'] ?? null)) {
            throw new UserFacingException('The model did not return a usable script.');
        }

        $turns = [];
        $chars = 0;
        foreach ($data['turns'] as $turn) {
            $line = is_array($turn) && is_string($turn['text'] ?? null) ? Podcast::stripTags($turn['text'], $tags) : '';
            if ($line === '') {
                continue;
            }
            // Stop at the first turn that does not fit: skipping it and keeping later, shorter ones would have host B answer a question nobody asked.
            if (count($turns) >= $maxTurns || $chars + mb_strlen($line) > $maxChars) {
                break;
            }
            $speaker = strtoupper((string) ($turn['speaker'] ?? '')) === 'B' ? 'B' : 'A';
            $turns[] = ['speaker' => $speaker, 'text' => $line];
            $chars += mb_strlen($line);
        }

        if (count($turns) < 2) {
            throw new UserFacingException('The script was too short to record.');
        }

        return ['title' => trim((string) ($data['title'] ?? '')) ?: 'Audio overview', 'turns' => $turns];
    }

    private function title(string $kind): string
    {
        return match ($kind) {
            'briefing' => 'briefing document',
            'study_guide' => 'study guide',
            'faq' => 'FAQ',
            default => $kind,
        };
    }
}
