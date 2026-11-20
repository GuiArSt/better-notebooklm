<?php

namespace App\Icons;

use App\Models\Notebook;
use Illuminate\Support\ServiceProvider;

class IconServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A new notebook asks for its icon after the transaction that made it commits; nothing happens when the switch is off.
        Notebook::created(fn (Notebook $notebook) => app(NotebookIcons::class)->request($notebook));
    }
}
