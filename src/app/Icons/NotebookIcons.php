<?php

namespace App\Icons;

use App\Models\Notebook;

/** The one entry point for "this notebook should get an icon", and the URL the interface asks for. */
class NotebookIcons
{
    /** The switch is on and OpenAI's key is present. */
    public static function available(): bool
    {
        return (bool) config('images.enabled') && (string) config('ai.providers.openai.key') !== '';
    }

    /** What the interface shows: the icon's address when it is done, otherwise null (the emoji stays). */
    public static function url(Notebook $notebook): ?string
    {
        if ($notebook->icon_status !== 'done' || ! $notebook->icon_path) {
            return null;
        }

        return route('notebooks.icon', ['notebook' => $notebook->id, 'v' => substr((string) $notebook->icon_sha256, 0, 12)], false);
    }

    /**
     * Ask for an icon, once: the status goes from nothing to `queued` in one atomic update, and only the request that made the flip
     * dispatches the job. A notebook with no real title gets none (there is nothing to draw).
     */
    public function request(Notebook $notebook, bool $retryFailed = false): bool
    {
        if (! self::available() || trim((string) $notebook->title) === '' || $notebook->title === 'Untitled notebook') {
            return false;
        }

        $flipped = Notebook::query()->whereKey($notebook->id)
            ->where(fn ($q) => $q->whereNull('icon_status')->when($retryFailed, fn ($q) => $q->orWhere('icon_status', 'failed')))
            ->update(['icon_status' => 'queued', 'icon_error' => null]);

        if ($flipped !== 1) {
            return false;
        }

        GenerateNotebookIcon::dispatch($notebook->id)->afterCommit();

        return true;
    }
}
