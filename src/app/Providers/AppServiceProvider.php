<?php

namespace App\Providers;

use App\Ai\DevFake;
use App\Models\Notebook;
use App\Sources\FileCleanup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // A notebook takes its files with it (sources/<id>, studio/<id>): see App\Sources\FileCleanup.
        Notebook::deleting(fn (Notebook $notebook) => app(FileCleanup::class)->forNotebook($notebook));

        // The footer's links, on every page: one value each in config/legal.php.
        Inertia::share('legal', fn () => [
            'impressum_url' => (string) config('legal.impressum_url'),
            'datenschutz_url' => (string) config('legal.datenschutz_url'),
        ]);

        if (config('ai.fake') && $this->app->isLocal()) {
            DevFake::register();
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
