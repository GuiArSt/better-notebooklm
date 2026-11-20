<?php

namespace App\Icons;

use App\Ai\Router;
use App\Ai\SafeLog;
use App\Models\Notebook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Draws one notebook's icon. Run once: the status flips queued -> running in a single update, so a retry or a duplicate job finds
 * nothing to do and never pays twice. Any failure ends `failed` with the error CLASS and the emoji stays. The title is never logged.
 */
class GenerateNotebookIcon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $notebookId) {}

    public function handle(Router $router, IconImage $images): void
    {
        $took = Notebook::query()->whereKey($this->notebookId)->where('icon_status', 'queued')->update(['icon_status' => 'running']);
        if ($took !== 1) {
            return;
        }

        $notebook = Notebook::with('user')->find($this->notebookId);
        if ($notebook === null || $notebook->user === null) {
            return;
        }

        try {
            $generated = $router->image($notebook->user, (string) $notebook->title, $notebook->id);
            $icon = $images->make($generated->bytes);

            $path = "icons/{$notebook->user_id}/{$notebook->id}.{$icon['ext']}";
            Storage::disk('local')->put($path, $icon['bytes']);

            Notebook::query()->whereKey($notebook->id)->update([
                'icon_status' => 'done', 'icon_path' => $path, 'icon_sha256' => hash('sha256', $icon['bytes']),
                'icon_width' => $icon['width'], 'icon_height' => $icon['height'], 'icon_error' => null,
            ]);
        } catch (Throwable $e) {
            SafeLog::failure('Notebook icon', $e, ['notebook_id' => $this->notebookId]);
            $this->failed($e);
        }
    }

    /** Called by the queue too (a timeout, a killed worker). Keeps the emoji; stores the class name only. */
    public function failed(Throwable $e): void
    {
        Notebook::query()->whereKey($this->notebookId)->whereIn('icon_status', ['queued', 'running'])
            ->update(['icon_status' => 'failed', 'icon_error' => mb_substr(class_basename($e), 0, 64)]);
    }
}
