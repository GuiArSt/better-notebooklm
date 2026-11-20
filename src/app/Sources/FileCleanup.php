<?php

namespace App\Sources;

use App\Models\Notebook;
use App\Models\Source;
use App\Models\StudioItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Deleting a row deletes its files (the privacy page promises it). One place for every kind of file:
 *   an uploaded source        sources/<notebook id>/<file>      (Source.file_path)
 *   a generated MP3           studio/<notebook id>/<item>.mp3   (StudioItem.file_path)
 *   a generated icon          icons/<user id>/<notebook id>.webp (Notebook.icon_path)
A notebook takes its folders and its icon with it.
 *
 * Eloquent deletes call this from `deleting` hooks (Source, StudioItem, and Notebook through AppServiceProvider). A row removed by the
 * database's own cascade (a deleted account) never reaches those hooks: that code calls forUser() first.
 * A file that is already gone is not an error.
 */
class FileCleanup
{
    public function forSource(Source $source): void
    {
        $this->delete($source->file_path);
    }

    public function forStudioItem(StudioItem $item): void
    {
        $this->delete($item->file_path);
    }

    public function forNotebook(Notebook $notebook): void
    {
        $disk = Storage::disk('local');
        $disk->deleteDirectory("sources/{$notebook->id}");
        $disk->deleteDirectory("studio/{$notebook->id}");
        $this->delete($notebook->icon_path);
    }

    /** Every notebook the account owns: call it BEFORE the user's rows are deleted. */
    public function forUser(User $user): void
    {
        Notebook::query()->where('user_id', $user->id)->each(fn (Notebook $notebook) => $this->forNotebook($notebook));
    }

    private function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('local')->delete($path);
        }
    }
}
