<?php

namespace App\Studio;

use App\Ai\Exceptions\UserFacingException;
use App\Ai\ImageResult;
use App\Ai\ModelJson;
use App\Ai\Router;
use App\Ai\SafeLog;
use App\Chat\CitationValidator;
use App\Chat\SourceBlock;
use App\Models\StudioItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Image;

/**
 * An infographic (D-045), in two steps, both through the Router (cap, ledger, trace): the text model writes a short cited brief from the
 * passages; then the image model draws ONE picture from that brief alone. The passages themselves never leave for the image model.
 */
class InfographicGenerator
{
    private const EXTENSIONS = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];

    public function __construct(private Router $router, private StudioGenerator $studio, private CitationValidator $validator) {}

    public function generate(StudioItem $item, User $user): void
    {
        if (! config('infographics.enabled') || ! InfographicOptions::allowedFor($user)) {
            throw new UserFacingException(InfographicOptions::REFUSAL); // access can lapse between asking and generating
        }
        $o = InfographicOptions::resolve((array) ($item->settings['options'] ?? []));

        $item->progress('Reading the sources', 1, 5);
        [$sources, $shownIds] = $this->studio->sourcesBlock($item, $user, 'the key facts and numbers');
        $language = $this->studio->language($item, $sources);

        $item->progress('Writing the brief', 2, 5);
        $variables = [
            'language' => $language, 'points' => (string) $o['points'], 'title_max' => (string) config('infographics.title_max'),
            'heading_max' => (string) config('infographics.heading_max'), 'figure_max' => (string) config('infographics.figure_max'), 'text_max' => (string) config('infographics.text_max'),
        ];
        $maxOut = (int) config('infographics.brief_max_output_tokens');
        $result = $this->router->text($user, 'studio.infographic', $variables, $sources.$this->studio->focusPart($item).SourceBlock::closing('Write the brief.'), 'chat', $item->notebook_id, maxOutputTokens: $maxOut);

        [$brief, $problems] = InfographicBrief::check($result->text, $shownIds, $o['points']);
        if ($brief === null) {
            // One repair call, with the checker's own words. A second failure ends the item cleanly (both calls are in the ledger).
            $repair = $this->router->text(
                $user, 'studio.infographic.repair', $variables,
                SourceBlock::sourceData('brief', $result->text).SourceBlock::closing("The problems:\n- ".implode("\n- ", array_slice($problems, 0, 25))."\n\nWrite the corrected brief."),
                'chat', $item->notebook_id, maxOutputTokens: $maxOut,
            );
            [$brief, $problems] = InfographicBrief::check($repair->text, $shownIds, $o['points']);
            $result = $repair;
            if ($brief === null) {
                SafeLog::failure('Infographic brief (refused twice by the checker)', new \RuntimeException, ['item_id' => $item->id, 'problems' => count($problems)]);
                throw new UserFacingException('The model did not return a usable brief for the infographic.');
            }
        }

        $refs = implode(' ', array_map(fn ($ref) => "[$ref]", array_values(array_unique(array_merge(...array_column($brief['points'], 'refs'))))));
        $resolved = $this->validator->resolve($refs, $item->notebook, $result->traceId, $shownIds);

        // The picture comes from the brief and nothing else: no passage, no description, no source title. Then the same chat model that wrote the
        // brief looks at the picture next to it (D-057): good, or bad and it is drawn once more with the problems. The last picture is kept.
        $subject = $this->subject($brief, $language, $o['orientation']);
        $review = null;
        $fix = [];
        for ($attempt = 1; $attempt <= (int) config('infographics.max_attempts'); $attempt++) {
            $item->progress($attempt === 1 ? 'Drawing the picture' : 'Drawing it again', $attempt === 1 ? 3 : 5, 5);
            $image = $this->router->image(
                $user, $subject.($fix === [] ? '' : "\n\nFix these problems seen in the last attempt: ".implode('; ', $fix)), $item->notebook_id,
                name: 'studio.infographic', size: $o['size'], quality: $o['quality'], style: InfographicOptions::styleText($o['style']), model: (string) config('infographics.model'),
            );

            $item->progress('Checking the picture', 4, 5);
            $review = $this->review($user, $item, $brief, $image, $language) + ['attempts' => $attempt];
            if ($review['verdict'] !== 'bad') {
                break;
            }
            $fix = $review['problems'];
        }

        $path = "studio/{$item->notebook_id}/{$item->id}.".(self::EXTENSIONS[$image->mime] ?? 'png');
        Storage::disk('local')->put($path, $image->bytes);

        $settings = (array) $item->settings;
        $settings['models']['image'] = ['provider' => 'openai', 'model' => (string) config('infographics.model'), 'size' => $o['size'], 'quality' => $o['quality']];
        $settings['review'] = $review; // verdict good | bad | unavailable, attempts, and the problems when bad (D-057: a visible note, nothing silent)
        $settings['infographic'] = InfographicOptions::labels((array) ($settings['options'] ?? []));
        if (count($brief['points']) !== $o['points']) {
            $settings['delivered'] = ['asked' => $o['points'], 'got' => count($brief['points'])]; // record only: 4 or more points ship
        }
        $item->update(['status' => 'done', 'step' => null, 'title' => $brief['title'], 'infographic' => $brief, 'citations' => $resolved['citations'], 'file_path' => $path, 'settings' => $settings]);
    }

    /**
     * Claude looks at the picture next to the brief (through the Router: cap, ledger, trace; the picture is attached, never traced). A review that
     * cannot be had, whatever the reason, never fails the item: the picture is kept and the verdict is "unavailable", logged by its class.
     *
     * @param  array{title: string, points: list<array<string, mixed>>}  $brief
     * @return array{verdict: string, problems: list<string>}
     */
    private function review(User $user, StudioItem $item, array $brief, ImageResult $image, string $language): array
    {
        $maxOut = (int) config('infographics.review_max_output_tokens');

        try {
            $result = $this->router->text(
                $user, 'studio.infographic.review', ['language' => $language],
                SourceBlock::sourceData('brief', (string) json_encode($brief, JSON_UNESCAPED_UNICODE)).SourceBlock::closing('Review the attached picture against the brief.'),
                'chat', $item->notebook_id, maxOutputTokens: $maxOut, schema: 'infographic_review', attachments: [new Base64Image(base64_encode($image->bytes), $image->mime)],
            );
            $data = app(ModelJson::class)->read($user, $result->text, 'The review could not be read.', $item->notebook_id, schema: 'infographic_review', maxOutputTokens: $maxOut);
        } catch (\Throwable $e) {
            SafeLog::failure('Infographic review (unavailable: the picture is kept)', $e, ['item_id' => $item->id]);

            return ['verdict' => 'unavailable', 'problems' => []];
        }

        $verdict = $data['verdict'] ?? null;
        if ($verdict !== 'good' && $verdict !== 'bad') {
            SafeLog::failure('Infographic review (unavailable: no verdict)', new \RuntimeException, ['item_id' => $item->id]);

            return ['verdict' => 'unavailable', 'problems' => []];
        }
        $problems = array_values(array_filter(array_map(fn ($p) => is_string($p) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($p))) : '', (array) ($data['problems'] ?? []))));

        return ['verdict' => $verdict, 'problems' => $verdict === 'bad' ? array_slice($problems, 0, (int) config('infographics.review_max_problems')) : []];
    }

    /**
     * What the image model is told to show: the brief's words, in the chosen language, on the chosen canvas.
     *
     * @param  array{title: string, points: list<array{heading: string, figure: string, text: string}>}  $brief
     */
    public function subject(array $brief, string $language, string $orientation): string
    {
        $lines = array_map(fn ($p, $i) => ($i + 1).'. '.$p['heading'].($p['figure'] === '' ? '' : ' ('.$p['figure'].')').': '.$p['text'], $brief['points'], array_keys($brief['points']));

        return "An infographic, $orientation layout, with every word written in $language. Title: {$brief['title']}. Show these points, each with its number:\n".implode("\n", $lines);
    }
}
