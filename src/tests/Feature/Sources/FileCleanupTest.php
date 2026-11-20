<?php

namespace Tests\Feature\Sources;

use App\Models\Notebook;
use App\Models\StudioItem;
use App\Models\User;
use App\Sources\FileCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** P-2 (Themis): deleting a row deletes its files. */
class FileCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function notebookWithFiles(User $user): array
    {
        Storage::fake('local');
        $notebook = Notebook::create(['user_id' => $user->id, 'title' => 'T']);
        Storage::disk('local')->put("sources/{$notebook->id}/a.pdf", 'pdf-a');
        Storage::disk('local')->put("sources/{$notebook->id}/b.pdf", 'pdf-b');
        Storage::disk('local')->put("studio/{$notebook->id}/1.mp3", 'mp3-1');
        Storage::disk('local')->put("studio/{$notebook->id}/2.mp3", 'mp3-2');
        $a = $notebook->sources()->create(['kind' => 'pdf', 'title' => 'a', 'status' => 'ready', 'file_path' => "sources/{$notebook->id}/a.pdf"]);
        $b = $notebook->sources()->create(['kind' => 'pdf', 'title' => 'b', 'status' => 'ready', 'file_path' => "sources/{$notebook->id}/b.pdf"]);
        $i1 = StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => 'audio', 'title' => 'one', 'status' => 'done', 'file_path' => "studio/{$notebook->id}/1.mp3"]);
        $i2 = StudioItem::create(['notebook_id' => $notebook->id, 'user_id' => $user->id, 'kind' => 'audio', 'title' => 'two', 'status' => 'done', 'file_path' => "studio/{$notebook->id}/2.mp3"]);

        return [$notebook, $a, $b, $i1, $i2];
    }

    public function test_deleting_a_source_deletes_its_file_and_only_its_file(): void
    {
        [$notebook, $a, $b] = $this->notebookWithFiles(User::factory()->create());

        $a->delete();

        Storage::disk('local')->assertMissing("sources/{$notebook->id}/a.pdf");
        Storage::disk('local')->assertExists("sources/{$notebook->id}/b.pdf");
    }

    public function test_deleting_a_studio_item_deletes_its_mp3(): void
    {
        [$notebook, , , $i1] = $this->notebookWithFiles(User::factory()->create());

        $i1->delete();

        Storage::disk('local')->assertMissing("studio/{$notebook->id}/1.mp3");
        Storage::disk('local')->assertExists("studio/{$notebook->id}/2.mp3");
    }

    public function test_deleting_a_notebook_deletes_its_whole_folders(): void
    {
        $user = User::factory()->create();
        [$notebook] = $this->notebookWithFiles($user);
        [$other] = [Notebook::create(['user_id' => $user->id, 'title' => 'Other'])];
        Storage::disk('local')->put("sources/{$other->id}/keep.pdf", 'keep');
        Storage::disk('local')->put("studio/{$other->id}/keep.mp3", 'keep');

        $notebook->delete();

        foreach (["sources/{$notebook->id}/a.pdf", "sources/{$notebook->id}/b.pdf", "studio/{$notebook->id}/1.mp3", "studio/{$notebook->id}/2.mp3"] as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        Storage::disk('local')->assertMissing("sources/{$notebook->id}");
        Storage::disk('local')->assertMissing("studio/{$notebook->id}");
        Storage::disk('local')->assertExists("sources/{$other->id}/keep.pdf");
        Storage::disk('local')->assertExists("studio/{$other->id}/keep.mp3");
    }

    public function test_the_endpoints_clean_up_too(): void
    {
        $user = User::factory()->create();
        [$notebook, $a, , $i1] = $this->notebookWithFiles($user);

        $this->actingAs($user)->delete("/notebooks/{$notebook->id}/sources/{$a->id}")->assertRedirect();
        $this->actingAs($user)->deleteJson("/notebooks/{$notebook->id}/studio/{$i1->id}")->assertOk();
        Storage::disk('local')->assertMissing("sources/{$notebook->id}/a.pdf");
        Storage::disk('local')->assertMissing("studio/{$notebook->id}/1.mp3");

        $this->actingAs($user)->delete("/notebooks/{$notebook->id}")->assertRedirect();
        Storage::disk('local')->assertMissing("sources/{$notebook->id}");
        Storage::disk('local')->assertMissing("studio/{$notebook->id}");
    }

    public function test_for_user_cleans_every_notebook_the_account_owns_before_the_rows_go(): void
    {
        // The account deletion removes rows by the database's cascade, which Eloquent never sees: it must call this first.
        $user = User::factory()->create();
        [$n1] = $this->notebookWithFiles($user);
        $n2 = Notebook::create(['user_id' => $user->id, 'title' => 'Two']);
        Storage::disk('local')->put("sources/{$n2->id}/x.pdf", 'x');
        $someoneElse = Notebook::create(['user_id' => User::factory()->create()->id, 'title' => 'Not mine']);
        Storage::disk('local')->put("sources/{$someoneElse->id}/y.pdf", 'y');

        app(FileCleanup::class)->forUser($user);

        Storage::disk('local')->assertMissing("sources/{$n1->id}");
        Storage::disk('local')->assertMissing("studio/{$n1->id}");
        Storage::disk('local')->assertMissing("sources/{$n2->id}");
        Storage::disk('local')->assertExists("sources/{$someoneElse->id}/y.pdf");
        $this->assertSame(2, Notebook::where('user_id', $user->id)->count(), 'cleanup touches files, not rows');
    }

    public function test_a_missing_file_is_not_an_error(): void
    {
        Storage::fake('local');
        $notebook = Notebook::create(['user_id' => User::factory()->create()->id, 'title' => 'T']);
        $source = $notebook->sources()->create(['kind' => 'pdf', 'title' => 'gone', 'status' => 'failed', 'file_path' => 'sources/1/never-there.pdf']);

        $source->delete();
        $notebook->delete();

        $this->assertSame(0, Notebook::count());
    }
}
