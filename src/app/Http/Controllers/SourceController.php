<?php

namespace App\Http\Controllers;

use App\Models\Notebook;
use App\Models\Source;
use App\Sources\EmbedSource;
use App\Sources\LargeSource;
use App\Sources\UrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SourceController extends Controller
{
    /**
     * Add a source: pasted text, a web page address, or an uploaded PDF/text file (exactly one).
     * It is stored at once; reading, embedding and summarising happen on the queue.
     */
    public function store(Request $request, Notebook $notebook, UrlFetcher $fetcher): RedirectResponse
    {
        abort_unless($notebook->user_id === $request->user()->id, 404);

        $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'content' => ['nullable', 'string', 'max:'.(int) config('retrieval.max_source_chars')],
            'url' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'mimes:pdf,txt,md', 'max:'.(int) config('sources.max_file_kb')],
        ], [
            // D-040: a refusal states the cap, in the app's own words (never a silent cut).
            'content.max' => LargeSource::refusal('This text'),
        ]);

        $given = collect(['content', 'url', 'file'])->filter(fn ($k) => $request->filled($k) || $request->hasFile($k));
        if ($given->count() !== 1) {
            throw ValidationException::withMessages(['content' => 'Add a pasted text, a web address or a file, one at a time.']);
        }

        $attributes = match ($given->first()) {
            'content' => $this->text($request, (string) $request->input('content'), (string) $request->input('title')),
            'url' => $this->web($request, $fetcher),
            default => $this->file($request, $notebook),
        };

        $source = $notebook->sources()->create($attributes + ['status' => 'pending']);
        EmbedSource::dispatch($source->id);

        return back();
    }

    public function update(Request $request, Notebook $notebook, Source $source): RedirectResponse
    {
        abort_unless($notebook->user_id === $request->user()->id && $source->notebook_id === $notebook->id, 404);

        $source->update($request->validate(['checked' => ['required', 'boolean']]));

        return back();
    }

    /** Try a failed source again (a web address or the stored file is read afresh). Only the owner, and only a source that failed. */
    public function retry(Request $request, Notebook $notebook, Source $source): RedirectResponse
    {
        abort_unless($notebook->user_id === $request->user()->id && $source->notebook_id === $notebook->id, 404);
        if ($source->status !== 'failed') {
            throw ValidationException::withMessages(['source' => 'Only a source that failed can be tried again.']);
        }

        $source->update(['status' => 'pending', 'error' => null]);
        EmbedSource::dispatch($source->id);

        return back();
    }

    public function destroy(Request $request, Notebook $notebook, Source $source): RedirectResponse
    {
        abort_unless($notebook->user_id === $request->user()->id && $source->notebook_id === $notebook->id, 404);

        $source->delete();

        return back();
    }

    /** The source for a viewer: its text, its page ranges and the offsets of every passage, so a citation can be highlighted. */
    public function show(Request $request, Notebook $notebook, Source $source): JsonResponse
    {
        abort_unless($notebook->user_id === $request->user()->id && $source->notebook_id === $notebook->id, 404);

        return response()->json([
            'id' => $source->id,
            'title' => $source->title,
            'kind' => $source->kind,
            'status' => $source->status,
            'error' => $source->error,
            'origin_url' => $source->origin_url,
            'summary' => $source->summary,
            'checked' => $source->checked,
            'content' => $source->content,
            'pages' => $source->pages,
            'passages' => $source->passages()->orderBy('position')->get(['id', 'page', 'start_offset as start', 'end_offset as end']),
        ]);
    }

    /** @return array<string, mixed> */
    private function text(Request $request, string $content, string $title): array
    {
        $request->validate(['title' => ['required', 'string', 'max:200']]);

        return ['kind' => 'text', 'title' => $title, 'content' => $content, 'chars' => mb_strlen($content)];
    }

    /** @return array<string, mixed> */
    private function web(Request $request, UrlFetcher $fetcher): array
    {
        $url = trim((string) $request->input('url'));
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw ValidationException::withMessages(['url' => 'Enter a web address starting with http:// or https://.']);
        }
        if (! $fetcher->isPublicUrl($url)) {
            throw ValidationException::withMessages(['url' => 'That address cannot be added: it must be a public web page.']);
        }

        return ['kind' => 'web', 'origin_url' => $url, 'title' => (string) ($request->input('title') ?: parse_url($url, PHP_URL_HOST))];
    }

    /** @return array<string, mixed> */
    private function file(Request $request, Notebook $notebook): array
    {
        $file = $request->file('file');
        $title = (string) ($request->input('title') ?: $file->getClientOriginalName());

        if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
            return ['kind' => 'pdf', 'title' => $title, 'file_path' => $file->store("sources/{$notebook->id}", 'local')];
        }

        $text = (string) file_get_contents($file->getRealPath());
        if (! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > (int) config('retrieval.max_source_chars')) {
            throw ValidationException::withMessages(['file' => mb_check_encoding($text, 'UTF-8') ? LargeSource::refusal('This text file') : 'The text file must be UTF-8.']);
        }

        return ['kind' => 'text', 'title' => $title, 'content' => $text, 'chars' => mb_strlen($text)];
    }
}
