<?php

use App\Http\Controllers\AboutController;
use Illuminate\Support\Facades\Route;

// The About page is public: signed out, the footer link must open it (D-059).
Route::get('about', AboutController::class)->name('about');
