<?php

namespace App\Sources;

use App\Ai\SafeLog;
use App\Models\Notebook;
use App\Models\NotebookOverview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Rebuilds a notebook's opening summary and suggested questions. A failure is logged, never shown as an error. */
class BuildOverview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // The overview is one Claude call (up to 300 s, the AI call's own timeout) and, if the language is wrong, one more: so 600 s. It must stay
    // below the queue's retry_after (config/queue.php), or a running job would be handed out a second time.
    public int $timeout = 600;

    public function __construct(public int $notebookId)
    {
        $this->onQueue('default');
    }

    public function handle(SourceSummary $summary): void
    {
        $notebook = Notebook::query()->with('user')->find($this->notebookId);
        if ($notebook === null) {
            return;
        }

        // The overview says what it is doing (D-038): the page no longer has to guess between "building", "failed" and "nothing to say".
        NotebookOverview::begin($notebook->id);

        try {
            $overview = $summary->overview($notebook->user, $notebook);
            // With no checked source the row is deleted: nothing to mark. A null with sources is an answer that could not be read.
            NotebookOverview::mark($notebook->id, $overview === null ? 'failed' : 'ready');
        } catch (Throwable $e) {
            SafeLog::failure('Notebook overview', $e, ['notebook_id' => $this->notebookId]);
            NotebookOverview::mark($notebook->id, 'failed');
        }
    }

    /** A killed or timed-out worker: the overview must not say "building" for ever. */
    public function failed(Throwable $e): void
    {
        NotebookOverview::mark($this->notebookId, 'failed', 'building');
    }
}
