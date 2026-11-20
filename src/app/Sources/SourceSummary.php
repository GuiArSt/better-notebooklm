<?php

namespace App\Sources;

use App\Ai\Exceptions\UserFacingException;
use App\Ai\Language;
use App\Ai\Router;
use App\Ai\SafeLog;
use App\Chat\SourceBlock;
use App\Models\Notebook;
use App\Models\NotebookOverview;
use App\Models\Source;
use App\Models\User;

/** The summary of one source, and the notebook's opening overview built from them. Both go through the Router. */
class SourceSummary
{
    public function __construct(private Router $router) {}

    public function summarise(User $user, Source $source): void
    {
        $text = mb_substr((string) $source->content, 0, (int) config('sources.summary_input_chars'));
        $code = $source->language ?? Language::guessCode($text);
        // The language is named in the system prompt AND at the end of the user turn: a small model weighs the last words most.
        $name = Language::name($code);
        $result = $this->router->text(
            $user, 'source.summary', ['language' => $name ?? 'the language of the source'],
            $this->block($source, $text).SourceBlock::closing($name !== null ? "Write the summary in {$name}." : 'Write the summary.'), 'chat', $source->notebook_id,
        );

        // Checked in code, not trusted: if the model still wrote another language, ask once more, firmly.
        if ($code !== null && ! Language::agrees($result->text, $code)) {
            $result = $this->router->text(
                $user, 'source.summary', ['language' => Language::name($code)],
                $this->block($source, $text).SourceBlock::closing('Your summary MUST be written in '.Language::name($code).' only, the language of the text above, whatever language you prefer.'), 'chat', $source->notebook_id,
            );
        }

        if ($code !== null && ! Language::agrees($result->text, $code)) {
            SafeLog::warning('Summary kept in another language than its source (retry did not help)', ['source_id' => $source->id, 'language' => $code]);
        }

        // An empty or cut summary is not stored as if it were whole: the caller logs the failure and the source stays ready without one.
        if (trim($result->text) === '' || $result->cutOff()) {
            throw new UserFacingException($result->cutOff() ? 'The summary hit its length limit.' : 'The model returned an empty summary.');
        }

        $source->update(['summary' => trim($result->text), 'language' => $code]);
    }

    /** A source's text as its block: a note the reader saved is the reader's own words (user_input), anything else is source data. */
    private function block(Source $source, string $text): string
    {
        return $source->kind === 'note' ? SourceBlock::userInput('note', $text) : SourceBlock::sourceData('source', $text);
    }

    public function overview(User $user, Notebook $notebook): ?NotebookOverview
    {
        $sources = $notebook->sources()->where('checked', true)->where('status', 'ready')->orderBy('id')->get();
        if ($sources->isEmpty()) {
            NotebookOverview::where('notebook_id', $notebook->id)->delete();

            return null;
        }

        $block = $sources->map(fn (Source $s) => SourceBlock::sourceData('summary', "{$s->title}\n".($s->summary ?: mb_substr((string) $s->content, 0, 600))))->implode("\n\n");
        // The majority language of the checked sources, by name; the model is never left to work it out.
        $code = Language::majority($sources->map(fn (Source $s) => $s->language ?? Language::guessCode((string) $s->content))->all());
        $language = Language::name($code) ?? 'the language of the sources';
        $result = $this->router->text($user, 'notebook.overview', ['language' => $language], $block.SourceBlock::closing($code !== null ? "Write the summary and the questions in {$language}." : 'Write the overview.'), 'chat', $notebook->id, schema: 'overview');
        if ($code !== null && ! Language::agrees($result->text, $code)) {
            $result = $this->router->text($user, 'notebook.overview', ['language' => $language], $block.SourceBlock::closing('Write the summary and the questions in '.$language.' only.'), 'chat', $notebook->id, schema: 'overview');
        }

        if ($code !== null && ! Language::agrees($result->text, $code)) {
            SafeLog::warning('Overview kept in another language than its sources (retry did not help)', ['notebook_id' => $notebook->id, 'language' => $code]);
        }

        $parsed = $this->parse($result->text);
        if ($parsed === null) {
            SafeLog::warning('Overview answer could not be read (the old overview stays)', ['notebook_id' => $notebook->id]);

            return null;
        }

        return NotebookOverview::updateOrCreate(['notebook_id' => $notebook->id], $parsed);
    }

    /** @return array{summary: string, questions: list<string>}|null */
    public function parse(string $text): ?array
    {
        // Models wrap JSON in fences or prose; take the outermost braces.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($data) || ! is_string($data['summary'] ?? null) || ! is_array($data['questions'] ?? null)) {
            return null;
        }

        $questions = array_values(array_filter(array_map(fn ($q) => is_string($q) ? trim($q) : '', $data['questions'])));

        return ['summary' => trim($data['summary']), 'questions' => array_slice($questions, 0, 3)];
    }
}
