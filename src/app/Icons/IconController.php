<?php

namespace App\Icons;

use App\Http\Controllers\Controller;
use App\Models\Notebook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** The icon, readable only by the notebook's owner. Never a public URL: it comes off the private disk through this route. */
class IconController extends Controller
{
    public function show(Request $request, Notebook $notebook): Response
    {
        abort_unless($notebook->user_id === $request->user()->id, 404);
        abort_unless($notebook->icon_status === 'done' && $notebook->icon_path && Storage::disk('local')->exists($notebook->icon_path), 404);

        return Storage::disk('local')->response($notebook->icon_path, null, [
            'Content-Type' => str_ends_with($notebook->icon_path, '.webp') ? 'image/webp' : 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
