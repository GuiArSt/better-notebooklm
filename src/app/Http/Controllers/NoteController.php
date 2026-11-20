<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\Notebook;
use App\Models\Source;
use App\Sources\EmbedSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Notes: the user's own writing or a pinned chat answer. A checked note is also a source (kind 'note'). */
class NoteController extends Controller
{
    public function store(Request $request, Notebook $notebook): RedirectResponse
    {
        $this->own($request, $notebook);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'content' => ['nullable', 'string', 'max:20000'],
            'citations' => ['nullable', 'array', 'max:50'],
            'citations.*.id' => ['required_with:citations', 'integer'],
            'citations.*.source_id' => ['required_with:citations', 'integer'],
            'citations.*.source_title' => ['nullable', 'string', 'max:300'],
            'citations.*.page' => ['nullable', 'integer'],
            'citations.*.start' => ['nullable', 'integer'],
            'citations.*.end' => ['nullable', 'integer'],
            'citations.*.quote' => ['nullable', 'string', 'max:2000'],
            'from_chat' => ['nullable', 'boolean'],
        ]);

        $notebook->notes()->create([
            'title' => $data['title'] ?? '',
            'content' => $data['content'] ?? '',
            'citations' => $data['citations'] ?? null,
            'from_chat' => (bool) ($data['from_chat'] ?? false),
        ]);

        return back();
    }

    public function update(Request $request, Notebook $notebook, Note $note): RedirectResponse
    {
        $this->own($request, $notebook, $note);

        $note->update($request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'checked' => ['sometimes', 'boolean'],
        ]));
        $this->syncSource($note);

        return back();
    }

    public function destroy(Request $request, Notebook $notebook, Note $note): RedirectResponse
    {
        $this->own($request, $notebook, $note);

        $note->source()->delete();
        $note->delete();

        return back();
    }

    /** A checked note has one source with its text; an unchecked one has none. Changed text is embedded again. */
    private function syncSource(Note $note): void
    {
        $text = $note->plainText();
        $title = $note->title !== '' ? $note->title : 'Note '.$note->id;

        if (! $note->checked || $text === '') {
            $note->source()->delete();
            $note->update(['source_id' => null, 'checked' => false]);

            return;
        }

        $source = $note->source;
        if ($source && $source->content === $text && $source->title === $title) {
            return;
        }

        $source ??= new Source(['notebook_id' => $note->notebook_id, 'kind' => 'note']);
        $source->fill(['title' => $title, 'content' => $text, 'status' => 'pending', 'error' => null, 'checked' => true, 'summary' => null])->save();
        $note->update(['source_id' => $source->id]);
        EmbedSource::dispatch($source->id);
    }

    private function own(Request $request, Notebook $notebook, ?Note $note = null): void
    {
        abort_unless($notebook->user_id === $request->user()->id && ($note === null || $note->notebook_id === $notebook->id), 404);
    }
}
