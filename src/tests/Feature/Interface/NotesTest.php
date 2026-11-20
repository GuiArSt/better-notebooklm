<?php

namespace Tests\Feature\Interface;

use App\Models\Notebook;
use App\Models\User;
use App\Sources\EmbedSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotesTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Notebook $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->me = User::factory()->create();
        $this->notebook = Notebook::create(['user_id' => $this->me->id, 'title' => 'N']);
        $this->actingAs($this->me);
    }

    public function test_write_edit_and_delete_a_note(): void
    {
        $this->post(route('notes.store', $this->notebook), ['title' => 'Idea', 'content' => 'First'])->assertRedirect();
        $note = $this->notebook->notes()->sole();

        $this->patch(route('notes.update', [$this->notebook, $note]), ['content' => 'Second'])->assertRedirect();
        $this->assertSame('Second', $note->fresh()->content);

        $this->delete(route('notes.destroy', [$this->notebook, $note]))->assertRedirect();
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_save_to_note_keeps_the_answer_and_its_citations(): void
    {
        $citation = ['id' => 12, 'source_id' => 3, 'source_title' => 'Doc', 'page' => null, 'start' => 0, 'end' => 20, 'quote' => 'a quote'];

        $this->post(route('notes.store', $this->notebook), ['content' => 'Cats purr [P12].', 'citations' => [$citation], 'from_chat' => true]);

        $note = $this->notebook->notes()->sole();
        $this->assertTrue($note->from_chat);
        $this->assertSame('a quote', $note->citations[0]['quote']);
        $this->assertSame('Cats purr.', $note->plainText());
    }

    public function test_checking_a_note_makes_it_a_source_and_unchecking_removes_it(): void
    {
        $note = $this->notebook->notes()->create(['title' => 'Facts', 'content' => 'Water is wet [P1].']);

        $this->patch(route('notes.update', [$this->notebook, $note]), ['checked' => true]);

        $source = $note->fresh()->source;
        $this->assertSame(['note', 'Facts', 'Water is wet.', 'pending', $this->notebook->id], [$source->kind, $source->title, $source->content, $source->status, $source->notebook_id]);
        Queue::assertPushed(EmbedSource::class, 1);

        $this->patch(route('notes.update', [$this->notebook, $note]), ['checked' => false]);
        $this->assertDatabaseCount('sources', 0);
        $this->assertNull($note->fresh()->source_id);
    }

    public function test_editing_a_checked_note_embeds_its_new_text(): void
    {
        $note = $this->notebook->notes()->create(['title' => 'Facts', 'content' => 'One.', 'checked' => true]);
        $this->patch(route('notes.update', [$this->notebook, $note]), ['content' => 'One.']); // creates the source
        $this->patch(route('notes.update', [$this->notebook, $note]), ['content' => 'Two.']);

        $this->assertSame('Two.', $note->fresh()->source->content);
        $this->assertDatabaseCount('sources', 1);
        Queue::assertPushed(EmbedSource::class, 2);
    }

    public function test_an_empty_note_cannot_be_a_source(): void
    {
        $note = $this->notebook->notes()->create(['title' => 'Blank', 'content' => '']);

        $this->patch(route('notes.update', [$this->notebook, $note]), ['checked' => true]);

        $this->assertDatabaseCount('sources', 0);
        $this->assertFalse($note->fresh()->checked);
    }

    public function test_deleting_a_note_deletes_its_source(): void
    {
        $note = $this->notebook->notes()->create(['title' => 'Facts', 'content' => 'x', 'checked' => true]);
        $this->patch(route('notes.update', [$this->notebook, $note]), ['content' => 'y']);
        $this->assertDatabaseCount('sources', 1);

        $this->delete(route('notes.destroy', [$this->notebook, $note]));
        $this->assertDatabaseCount('sources', 0);
    }

    public function test_nobody_else_can_touch_my_notes(): void
    {
        $note = $this->notebook->notes()->create(['content' => 'x']);
        $this->actingAs(User::factory()->create());

        $this->post(route('notes.store', $this->notebook), ['content' => 'y'])->assertNotFound();
        $this->patch(route('notes.update', [$this->notebook, $note]), ['content' => 'y'])->assertNotFound();
        $this->delete(route('notes.destroy', [$this->notebook, $note]))->assertNotFound();
    }

    public function test_a_grouped_citation_marker_is_stripped_from_the_note_as_a_source(): void
    {
        $note = $this->notebook->notes()->create(['title' => 'Facts', 'content' => 'A claim [P260, P261]. Another [P7].']);

        $this->assertSame('A claim. Another.', $note->plainText());
    }

    public function test_semicolon_and_spaced_citation_markers_are_stripped_like_the_server_reads_them(): void
    {
        $note = $this->notebook->notes()->create(['title' => 'Facts', 'content' => 'A [P1; P2] and B [ P3 ] end.']);

        $this->assertSame('A and B end.', $note->plainText());
    }
}
