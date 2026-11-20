<?php

namespace App\Http\Controllers;

use App\Models\Notebook;
use App\Models\StudioItem;
use App\Studio\GenerateStudioItem;
use App\Studio\InfographicOptions;
use App\Studio\ItemSettings;
use App\Studio\Podcast;
use App\Studio\StudioEstimate;
use App\Studio\StudioOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudioController extends Controller
{
    public function index(Request $request, Notebook $notebook): JsonResponse
    {
        $this->authorizeNotebook($request, $notebook);

        return response()->json(['items' => StudioItem::where('notebook_id', $notebook->id)->latest('id')->get()->map->toArrayForClient()]);
    }

    /**
     * Start a generation. It is queued ("generate later"); poll the item for its step and result.
     * Besides `kind`, the Customize step (D-035) may send: language ("auto" or en, fr, de, es, it, pt, nl), sources (ids), focus (plain text,
     * at most 500 characters) and options (audio: length; quiz: questions, difficulty; slides: slides). All are recorded in the item's settings.
     */
    public function store(Request $request, Notebook $notebook, ItemSettings $settings): JsonResponse
    {
        $this->authorizeNotebook($request, $notebook);
        $kind = $request->validate(['kind' => ['required', Rule::in(config('studio.kinds'))]])['kind'];
        $data = $request->validate(StudioOptions::rules($kind));
        $choices = StudioOptions::normalise($notebook, $kind, $data, (array) $request->input('options', []));

        return $this->start($request, $notebook, $kind, $settings->make($request->user(), $notebook, $kind, $choices));
    }

    /** What the item would cost at most, from the price config: GET .../studio/estimate?kind=quiz&options[questions]=8 */
    public function estimate(Request $request, Notebook $notebook, StudioEstimate $estimate): JsonResponse
    {
        $this->authorizeNotebook($request, $notebook);
        $kind = $request->validate(['kind' => ['required', Rule::in(config('studio.kinds'))]])['kind'];
        $data = $request->validate(StudioOptions::rules($kind));

        return response()->json($estimate->for($request->user(), $notebook, $kind, StudioOptions::normalise($notebook, $kind, $data, (array) $request->input('options', []))));
    }

    /** Rename: plain text, 1 to 120 characters. */
    public function update(Request $request, Notebook $notebook, StudioItem $item): JsonResponse
    {
        $this->authorizeItem($request, $notebook, $item);
        $data = $request->validate(['title' => ['required', 'string', 'min:1', 'max:120']]);

        $title = trim(strip_tags($data['title']));
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Give it a name.']);
        }
        $item->update(['title' => $title]);

        return response()->json($item->toArrayForClient());
    }

    /** Make it again: a NEW item with the same settings, through the same path as store (caps, ledger). The old item stays. */
    public function regenerate(Request $request, Notebook $notebook, StudioItem $item, ItemSettings $settings): JsonResponse
    {
        $this->authorizeItem($request, $notebook, $item);

        // The settings of an older item may be missing (made before they were recorded): work them out from what is there now.
        $copy = is_array($item->settings) ? $item->settings : $settings->make($request->user(), $notebook, $item->kind);
        Podcast::requirePersonaAccess($request->user(), (array) ($copy['options'] ?? [])); // a lapsed pro keeps no persona
        if ($item->kind === 'infographic') {
            InfographicOptions::require($request->user()); // a lapsed pro keeps no infographic
        }
        // What was true of the OLD run (its cost, length, trimming, delivered count, voice fallback) is not copied onto the new one.
        unset($copy['cost_micro_usd'], $copy['duration_seconds'], $copy['trimmed'], $copy['delivered'], $copy['voice_fallback']);
        $copy['regenerated_from'] = $item->id;

        return $this->start($request, $notebook, $item->kind, $copy);
    }

    /** @param  array<string, mixed>  $settings */
    private function start(Request $request, Notebook $notebook, string $kind, array $settings): JsonResponse
    {
        if (! $notebook->sources()->where('checked', true)->where('status', 'ready')->exists()) {
            throw ValidationException::withMessages(['kind' => 'Add a source and keep it checked first.']);
        }

        $inFlight = StudioItem::where('notebook_id', $notebook->id)->whereIn('status', ['pending', 'running'])->count();
        if ($inFlight >= (int) config('studio.max_in_flight')) {
            $n = (int) config('studio.max_in_flight');

            return response()->json(['message' => ucfirst(Number::spell($n)).($n === 1 ? ' item is' : ' items are').' already being made. Wait for one to finish.'], 429);
        }

        $item = StudioItem::create([
            'notebook_id' => $notebook->id, 'user_id' => $request->user()->id,
            'kind' => $kind, 'title' => (string) config("studio.titles.$kind", Str::headline($kind)), 'status' => 'pending', 'settings' => $settings,
        ]);
        GenerateStudioItem::dispatch($item->id, $kind === 'audio' ? 'media' : 'default');

        return response()->json($item->toArrayForClient(), 202);
    }

    public function show(Request $request, Notebook $notebook, StudioItem $item): JsonResponse
    {
        $this->authorizeItem($request, $notebook, $item);

        return response()->json($item->toArrayForClient());
    }

    public function audio(Request $request, Notebook $notebook, StudioItem $item): BinaryFileResponse
    {
        $this->authorizeItem($request, $notebook, $item);
        abort_unless($item->file_path && Storage::disk('local')->exists($item->file_path), 404);

        return response()->file(Storage::disk('local')->path($item->file_path), ['Content-Type' => 'audio/mpeg']);
    }

    public function destroy(Request $request, Notebook $notebook, StudioItem $item): JsonResponse
    {
        $this->authorizeItem($request, $notebook, $item);
        $item->delete(); // the model's deleting hook removes the MP3 (App\Sources\FileCleanup)

        return response()->json(['deleted' => true]);
    }

    private function authorizeNotebook(Request $request, Notebook $notebook): void
    {
        abort_unless($notebook->user_id === $request->user()->id, 404);
    }

    private function authorizeItem(Request $request, Notebook $notebook, StudioItem $item): void
    {
        $this->authorizeNotebook($request, $notebook);
        abort_unless($item->notebook_id === $notebook->id, 404);
    }
}
