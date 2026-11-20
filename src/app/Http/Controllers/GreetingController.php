<?php

namespace App\Http\Controllers;

use App\Frontend\PodcastCost;
use App\Frontend\UsageMeter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The first screen after sign-in (D-006): a demo for a test, what works, what does not, the budget, the traces. */
class GreetingController extends Controller
{
    /** Where sign-in lands: the greeting once, then the notebooks. */
    public function enter(Request $request): RedirectResponse
    {
        return $this->greeted($request)
            ? redirect()->route('notebooks.index')
            : redirect()->route('greeting.show');
    }

    public function show(Request $request, UsageMeter $meter, PodcastCost $podcast): Response
    {
        return Inertia::render('greeting', [
            'usage' => $meter->for($request->user()),
            'podcast_usd' => $podcast->maxUsd($request->user()),
            'infographics' => (bool) config('infographics.enabled'),
            'greeted' => $this->greeted($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        DB::table('greetings')->insertOrIgnore(['user_id' => $request->user()->id, 'acknowledged_at' => now()]);

        return redirect()->route('notebooks.index');
    }

    private function greeted(Request $request): bool
    {
        return DB::table('greetings')->where('user_id', $request->user()->id)->exists();
    }
}
