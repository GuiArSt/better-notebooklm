<?php

use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Middleware\RequireRole;
use Illuminate\Support\Facades\Route;

// Sign-up is closed: the only way in is an invitation link (signed, expiring, single-use).
Route::middleware(['guest', 'throttle:invitation'])->group(function () {
    Route::get('invitation/{invitation}', [InvitationController::class, 'show'])
        ->middleware('signed')->name('invitation.show');
    Route::post('invitation/{invitation}', [InvitationController::class, 'store'])
        ->middleware('signed')->name('invitation.accept');
});

// Google sign-in: only for an address that holds an invitation or an account.
Route::middleware(['guest', 'throttle:google'])->group(function () {
    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
});

// Owner-only admin: users and invitations.
Route::middleware(['auth', 'verified', RequireRole::class.':owner'])->prefix('admin')->group(function () {
    Route::get('users', [UserAdminController::class, 'index'])->name('admin.users');
    Route::post('invitations', [UserAdminController::class, 'invite'])->middleware('throttle:20,1')->name('admin.invitations.store');
});
