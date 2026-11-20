<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    require __DIR__.'/app.php';
    require __DIR__.'/notebooks.php';
    require __DIR__.'/billing.php';
});

require __DIR__.'/about.php';
require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
