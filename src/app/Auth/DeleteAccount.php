<?php

namespace App\Auth;

use App\Models\Invitation;
use App\Models\Notebook;
use App\Models\User;
use App\Sources\FileCleanup;
use Illuminate\Support\Facades\Log;

/**
 * Deletes an account and everything that belongs to it (P-1, GDPR Art. 17): the files on disk (PDFs, audio, icons),
 * the invitations for the user's email, then the user row, from which the database cascades the rest.
 * It logs counts, never contents.
 */
class DeleteAccount
{
    public function __invoke(User $user): void
    {
        $notebooks = Notebook::where('user_id', $user->id)->count();

        // Before the rows go: the database's own cascade does not reach the file cleanup hooks.
        app(FileCleanup::class)->forUser($user);

        $invitations = Invitation::where('email', mb_strtolower($user->email))->delete();

        $user->delete();

        Log::info('account deleted', ['notebooks' => $notebooks, 'invitations' => $invitations]);
    }
}
