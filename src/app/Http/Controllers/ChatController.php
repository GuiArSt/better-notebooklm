<?php

namespace App\Http\Controllers;

use App\Chat\ChatService;
use App\Models\Notebook;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /** Server-sent events: text deltas, then one citations event, then done. */
    public function store(Request $request, Notebook $notebook, ChatService $chat): StreamedResponse
    {
        abort_unless($notebook->user_id === $request->user()->id, 404);
        $data = $request->validate(['question' => ['required', 'string', 'max:2000']]);
        $user = $request->user();

        return response()->stream(function () use ($chat, $user, $notebook, $data) {
            // If the reader hangs up, keep running just long enough to settle what was streamed (see Router::stream).
            ignore_user_abort(true);

            foreach ($chat->answer($user, $notebook, $data['question']) as $event) {
                echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if (connection_aborted()) {
                    break; // dropping the generator settles the call on what was produced
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
