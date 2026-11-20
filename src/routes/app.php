<?php

use App\Http\Controllers\GreetingController;
use App\Http\Controllers\NotebookController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\TermsController;
use Illuminate\Support\Facades\Route;

// The interface's routes. Loaded from web.php inside the auth + verified group.
Route::get('dashboard', [GreetingController::class, 'enter'])->name('dashboard');
Route::get('greeting', [GreetingController::class, 'show'])->name('greeting.show');
Route::post('greeting', [GreetingController::class, 'store'])->name('greeting.store');
Route::get('pricing', [PricingController::class, 'show'])->name('pricing');

Route::get('notebooks', [NotebookController::class, 'index'])->name('notebooks.index');
Route::post('notebooks', [NotebookController::class, 'store'])->name('notebooks.store');
Route::post('notebooks/featured', [NotebookController::class, 'featured'])->name('notebooks.featured');
Route::get('notebooks/{notebook}', [NotebookController::class, 'show'])->name('notebooks.show');
Route::patch('notebooks/{notebook}', [NotebookController::class, 'update'])->name('notebooks.update');
Route::delete('notebooks/{notebook}', [NotebookController::class, 'destroy'])->name('notebooks.destroy');

Route::post('notebooks/{notebook}/notes', [NoteController::class, 'store'])->name('notes.store');
Route::patch('notebooks/{notebook}/notes/{note}', [NoteController::class, 'update'])->name('notes.update');
Route::delete('notebooks/{notebook}/notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');

// The privacy page is public: signed out, the footer link and the greeting must open it.
Route::withoutMiddleware(['auth', 'verified'])->get('privacy', PrivacyController::class)->name('privacy');

// The terms page is public too: Google's consent screen links to exactly /terms.
Route::withoutMiddleware(['auth', 'verified'])->get('terms', TermsController::class)->name('terms');
