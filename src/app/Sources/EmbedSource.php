<?php

namespace App\Sources;

use App\Ai\FriendlyError;
use App\Ai\Language;
use App\Ai\Router;
use App\Ai\SafeLog;
use App\Models\Passage;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Chunks a source, embeds its passages through the Router, and marks it ready. Runs on the `default` queue. */
class EmbedSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // A heavy source (D-040) is read in minutes, not seconds: measured 1 minute for 590,540 characters and 5 for 1.2 million, with the provider's
    // own speed varying 3x between runs. The worker's own --timeout and the queue's retry_after must be longer than this (deploy/).
    public int $timeout = 900;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('default');
    }

    public function handle(Chunker $chunker, Router $router, SourceLoader $loader, SourceSummary $summaries): void
    {
        $source = Source::query()->with('notebook')->findOrFail($this->sourceId);
        $user = $source->notebook->user;

        try {
            $loader->load($source);
            $source->passages()->delete();
            $pages = $source->pageRanges();
            $chunks = $pages !== null
                ? $chunker->chunkPages((string) $source->content, $pages)
                : $chunker->chunk((string) $source->content);

            $position = 0;
            foreach (array_chunk($chunks, 32) as $batch) {
                ['vectors' => $vectors] = $router->embed($user, array_column($batch, 'text'), $source->notebook_id);

                foreach ($batch as $i => $chunk) {
                    Passage::create([
                        'notebook_id' => $source->notebook_id,
                        'source_id' => $source->id,
                        'position' => $position++,
                        'page' => $chunk['page'] ?? null,
                        'start_offset' => $chunk['start'],
                        'end_offset' => $chunk['end'],
                        'content' => $chunk['text'],
                        'embedding' => $vectors[$i],
                    ]);
                }
            }

            // The source's language is told in code, here, once; every prompt that follows names it (a model left to infer it drifts).
            $source->update(['status' => 'ready', 'error' => null, 'language' => Language::guessCode((string) $source->content)]);

            // The summary and the notebook overview are extras: the source is already usable if they fail.
            try {
                $summaries->summarise($user, $source);
                BuildOverview::dispatch($source->notebook_id);
            } catch (Throwable $e) {
                SafeLog::failure('Source summary', $e, ['source_id' => $source->id]);
            }
        } catch (Throwable $e) {
            $source->passages()->delete();
            $source->update(['status' => 'failed', 'error' => FriendlyError::message($e)]);

            SafeLog::failure('Source embedding', $e, ['source_id' => $source->id]);
        }
    }

    /** A killed or timed-out worker (memory, timeout): the source must not stay "pending" for ever. */
    public function failed(Throwable $e): void
    {
        Source::where('id', $this->sourceId)->whereIn('status', ['pending'])
            ->update(['status' => 'failed', 'error' => FriendlyError::GENERIC]);
    }
}
