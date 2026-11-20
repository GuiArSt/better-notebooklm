<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\StudioController;
use App\Icons\IconController;
use App\Studio\InfographicController;
use Illuminate\Support\Facades\Route;

// Sources, chat and the notebook overview. Loaded from web.php inside the auth group.
Route::post('notebooks/{notebook}/sources', [SourceController::class, 'store'])->name('sources.store');
Route::get('notebooks/{notebook}/sources/{source}', [SourceController::class, 'show'])->name('sources.show');
Route::patch('notebooks/{notebook}/sources/{source}', [SourceController::class, 'update'])->name('sources.update');
Route::delete('notebooks/{notebook}/sources/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
Route::post('notebooks/{notebook}/sources/{source}/retry', [SourceController::class, 'retry'])->name('sources.retry');
Route::post('notebooks/{notebook}/chat', [ChatController::class, 'store'])->name('chat.store');
Route::get('notebooks/{notebook}/overview', [OverviewController::class, 'show'])->name('overview.show');
Route::post('notebooks/{notebook}/overview', [OverviewController::class, 'refresh'])->name('overview.refresh');

// The Studio: audio overview and reports, generated on the queue.
Route::get('notebooks/{notebook}/studio', [StudioController::class, 'index'])->name('studio.index');
Route::post('notebooks/{notebook}/studio', [StudioController::class, 'store'])->name('studio.store');
Route::get('notebooks/{notebook}/studio/estimate', [StudioController::class, 'estimate'])->name('studio.estimate');
Route::get('notebooks/{notebook}/studio/{item}', [StudioController::class, 'show'])->name('studio.show');
Route::get('notebooks/{notebook}/studio/{item}/audio', [StudioController::class, 'audio'])->name('studio.audio');
Route::get('notebooks/{notebook}/studio/{item}/image', [InfographicController::class, 'image'])->name('studio.image');
Route::patch('notebooks/{notebook}/studio/{item}', [StudioController::class, 'update'])->name('studio.update');
Route::post('notebooks/{notebook}/studio/{item}/regenerate', [StudioController::class, 'regenerate'])->name('studio.regenerate');
Route::delete('notebooks/{notebook}/studio/{item}', [StudioController::class, 'destroy'])->name('studio.destroy');

// A notebook's generated icon (D-032): the owner's only, off the private disk.
Route::get('notebooks/{notebook}/icon', [IconController::class, 'show'])->name('notebooks.icon');
