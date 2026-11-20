<?php

namespace App\Http\Controllers;

use App\Frontend\Featured;
use App\Frontend\InfographicChoices;
use App\Frontend\PodcastChoices;
use App\Frontend\UsageMeter;
use App\Models\Notebook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotebookController extends Controller
{
    /** Home: the user's notebooks and the one featured demo notebook. */
    public function index(Request $request, UsageMeter $meter): Response
    {
        $user = $request->user();

        // Their own copy of the featured notebook is listed below; the card would show the same notebook a second time, with another icon.
        $hasCopy = Notebook::query()->where('user_id', $user->id)->where('featured_slug', Featured::SLUG)->exists();

        $notebooks = Notebook::query()->where('user_id', $user->id)->withCount('sources')
            ->latest('updated_at')->get()
            ->map(fn (Notebook $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'emoji' => $n->emoji,
                'icon_status' => $n->icon_status,
                'sources_count' => $n->sources_count,
                'updated_at' => $n->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('notebooks/index', [
            'notebooks' => $notebooks,
            'featured' => $hasCopy ? null : Featured::card(),
            'usage' => $meter->for($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $notebook = Notebook::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'] ?? 'Untitled notebook',
            'emoji' => $data['emoji'] ?? '📓',
        ]);

        return redirect()->route('notebooks.show', $notebook);
    }

    /** Open the featured demo notebook, making the user's own copy the first time. */
    public function featured(Request $request): RedirectResponse
    {
        return redirect()->route('notebooks.show', Featured::open($request->user()));
    }

    public function update(Request $request, Notebook $notebook): RedirectResponse
    {
        $this->own($request, $notebook);
        $notebook->update(array_filter($request->validate($this->rules()), fn ($v) => $v !== null && $v !== ''));

        return back();
    }

    public function destroy(Request $request, Notebook $notebook): RedirectResponse
    {
        $this->own($request, $notebook);
        $notebook->delete();

        return redirect()->route('notebooks.index');
    }

    public function show(Request $request, Notebook $notebook, UsageMeter $meter, PodcastChoices $podcast, InfographicChoices $infographic): Response
    {
        $this->own($request, $notebook);

        return Inertia::render('notebooks/show', [
            'notebook' => $notebook->only('id', 'title', 'emoji', 'icon_status'),
            // `chars` and `pages` feed the large-source warning (D-040); the page ranges themselves stay on the server.
            'sources' => $notebook->sources()->orderBy('id')->get(['id', 'title', 'kind', 'status', 'checked', 'error', 'origin_url', 'chars', 'pages'])->makeHidden(['pages', 'chars']),
            'notes' => $notebook->notes()->latest('id')->get(['id', 'title', 'content', 'citations', 'from_chat', 'checked', 'updated_at']),
            'usage' => $meter->for($request->user()),
            'maxSourceChars' => (int) config('retrieval.max_source_chars'),
            'podcast' => $podcast->for($request->user()),
            'infographic' => $infographic->for($request->user()),
        ]);
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:120'],
            'emoji' => ['nullable', 'string', 'max:16'],
        ];
    }

    private function own(Request $request, Notebook $notebook): void
    {
        abort_unless($notebook->user_id === $request->user()->id, 404);
    }
}
