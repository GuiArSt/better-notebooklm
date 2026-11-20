<?php

namespace App\Http\Controllers;

use App\Models\Notebook;
use App\Models\NotebookOverview;
use App\Sources\BuildOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    /**
     * {summary, questions[3], status}: status is "building", "ready" or "failed", or null when no overview was ever asked for. A row from before
     * the states existed (status null, with text) reads as "ready". While "building" or "failed" the summary and questions are those of the last
     * good overview, or null and [] when there never was one.
     */
    public function show(Request $request, Notebook $notebook): JsonResponse
    {
        abort_unless($notebook->user_id === $request->user()->id, 404);
        $overview = NotebookOverview::where('notebook_id', $notebook->id)->first();

        return response()->json([
            'summary' => $overview?->summary ?: null, // an empty summary (a row that only says building or failed) is no summary
            'questions' => $overview?->questions ?? [],
            'status' => $overview === null ? null : ($overview->status ?? 'ready'),
        ]);
    }

    /** Ask for a fresh overview (after sources were checked or unchecked). It is built on the queue. */
    public function refresh(Request $request, Notebook $notebook): JsonResponse
    {
        abort_unless($notebook->user_id === $request->user()->id, 404);
        BuildOverview::dispatch($notebook->id);

        return response()->json(['queued' => true], 202);
    }
}
