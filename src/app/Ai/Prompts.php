<?php

namespace App\Ai;

use Axyr\Langfuse\LangfuseFacade as Langfuse;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * A prompt is named (chat.answer, source.summary, ...). Langfuse's `production` label is the live version;
 * if Langfuse is unreachable or has none, the copy in resources/prompts/<name>.md answers, so tests and an outage still work.
 */
class Prompts
{
    /** @param  array<string, string>  $variables */
    public function get(string $name, array $variables = []): PromptVersion
    {
        $fallback = $this->repoCopy($name);
        $variables = array_map(strval(...), $variables);

        // PROMPTS_SOURCE=repo (the default): the prompts in this image answer, always. Langfuse still traces every call with the prompt's name and
        // version ("repo"). An old prompt labelled `production` there, from before the fenced prompts of D-046, can then never replace them. The owner
        // switches to `langfuse` after he has pushed the repo copies and moved the label.
        if (config('ai.prompts_source') !== 'langfuse') {
            return new PromptVersion($name, 0, $this->fill($fallback, $variables), true);
        }

        try {
            $prompt = Langfuse::prompt($name, label: 'production', fallback: $fallback);

            // Langfuse's `production` label is shared by dev and prod. A version whose {{variables}} are not the repo copy's would either be
            // ignored by an image that gained variables, or go out with variables its caller never fills. Then the repo copy answers.
            if (! $prompt->isFallback()) {
                $theirs = $this->variablesIn($this->asText($prompt->compile([])));
                $ours = $this->variablesIn($fallback);
                if ($theirs !== $ours) {
                    SafeLog::warning('Langfuse prompt has other {{variables}} than the repo copy: the repo copy answers', [
                        'prompt' => $name, 'langfuse_version' => $prompt->getVersion(),
                        'langfuse_variables' => implode(',', $theirs), 'repo_variables' => implode(',', $ours),
                    ]);

                    return new PromptVersion($name, 0, $this->fill($fallback, $variables), true);
                }
            }

            $text = $prompt->compile($variables);

            return new PromptVersion($name, $prompt->getVersion(), is_string($text) ? $text : json_encode($text, JSON_THROW_ON_ERROR), $prompt->isFallback(), $prompt);
        } catch (Throwable $e) {
            SafeLog::failure('Langfuse prompt (the repo copy answers)', $e, ['prompt' => $name]);

            return new PromptVersion($name, 0, $this->fill($fallback, $variables), true);
        }
    }

    /** @return list<string> the names of the {{variables}} in a template, sorted, once each */
    private function variablesIn(string $template): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /** @param  string|array<mixed>  $compiled */
    private function asText(string|array $compiled): string
    {
        return is_string($compiled) ? $compiled : json_encode($compiled, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> name => text, for every repo copy */
    public function repoCopies(): array
    {
        $copies = [];
        foreach (File::glob(resource_path('prompts/*.md')) as $file) {
            $copies[basename($file, '.md')] = trim(File::get($file));
        }

        return $copies;
    }

    private function repoCopy(string $name): string
    {
        $path = resource_path("prompts/$name.md");

        return File::exists($path) ? trim(File::get($path)) : throw new \InvalidArgumentException("No prompt named [$name].");
    }

    /** @param  array<string, string>  $variables */
    private function fill(string $text, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }

        return $text;
    }
}
