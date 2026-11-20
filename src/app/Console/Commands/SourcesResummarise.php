<?php

namespace App\Console\Commands;

use App\Ai\Language;
use App\Models\Source;
use App\Sources\BuildOverview;
use App\Sources\SourceSummary;
use Illuminate\Console\Command;
use Throwable;

class SourcesResummarise extends Command
{
    protected $signature = 'sources:resummarise {--mismatched : redo the summary of every ready source whose summary is in another language than the source}';

    protected $description = 'Tell each ready source\'s language in code, and write the summary again where the stored one is in another language (then rebuild that notebook\'s overview)';

    public function handle(SourceSummary $summaries): int
    {
        if (! $this->option('mismatched')) {
            $this->error('Say what to do: --mismatched.');

            return self::FAILURE;
        }

        $checked = $fixed = $failed = 0;
        $notebooks = [];
        Source::query()->where('status', 'ready')->with('notebook.user')->orderBy('id')->each(function (Source $source) use ($summaries, &$checked, &$fixed, &$failed, &$notebooks) {
            $checked++;
            $code = Language::guessCode((string) $source->content);
            if ($source->language !== $code) {
                $source->update(['language' => $code]);
            }
            if ($code === null || $source->summary === null || Language::agrees((string) $source->summary, $code)) {
                return;
            }
            try {
                $summaries->summarise($source->notebook->user, $source);
                $notebooks[$source->notebook_id] = true;
                $fixed++;
            } catch (Throwable $e) {
                $failed++; // no message, no text: the class only
                $this->warn('source '.$source->id.': '.class_basename($e));
            }
        });

        foreach (array_keys($notebooks) as $id) {
            BuildOverview::dispatch($id);
        }

        $this->components->info("$checked source(s) checked, $fixed summary(ies) rewritten, $failed failed, ".count($notebooks).' overview(s) queued.');

        return self::SUCCESS;
    }
}
