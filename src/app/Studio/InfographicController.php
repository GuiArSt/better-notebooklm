<?php

namespace App\Studio;

use App\Http\Controllers\Controller;
use App\Models\Notebook;
use App\Models\StudioItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** An infographic's picture, off the private disk, for the notebook's owner only (like the notebook icon, D-032). ?download=1 saves it. */
class InfographicController extends Controller
{
    public function image(Request $request, Notebook $notebook, StudioItem $item): BinaryFileResponse
    {
        abort_unless($notebook->user_id === $request->user()->id && $item->notebook_id === $notebook->id && $item->kind === 'infographic', 404);
        abort_unless($item->file_path && Storage::disk('local')->exists($item->file_path), 404);

        $path = Storage::disk('local')->path($item->file_path);
        $headers = ['Content-Type' => Storage::disk('local')->mimeType($item->file_path), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600'];

        return $request->boolean('download')
            ? response()->download($path, (Str::slug((string) $item->title) ?: 'infographic').'.'.pathinfo($path, PATHINFO_EXTENSION), $headers)
            : response()->file($path, $headers);
    }
}
