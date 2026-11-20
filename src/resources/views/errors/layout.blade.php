<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') - {{ config('app.name', 'Laravel') }}</title>

        {{-- The same theme choice as the app: the system's, applied before the first paint. --}}
        <script>
            (function () {
                if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>
        <style>
            html { background-color: oklch(1 0 0); }
            html.dark { background-color: oklch(0.145 0 0); }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @fonts
        @vite('resources/css/app.css')
    </head>
    <body class="font-sans antialiased">
        <main class="mx-auto flex min-h-svh max-w-md flex-col items-center justify-center gap-6 px-6 text-center" data-testid="error-page">
            <span class="flex size-14 items-center justify-center rounded-2xl bg-foreground text-background">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-8" role="img" aria-label="{{ config('app.name') }}">
                    <path d="M8.5 5H5v14h3.5 M15.5 5H19v14h-3.5" />
                    <rect x="7.25" y="7.25" width="3.75" height="3.75" rx="0.75" fill="currentColor" stroke="none" />
                    <rect x="12.5" y="7.25" width="3.75" height="3.75" rx="0.75" fill="currentColor" stroke="none" />
                    <rect x="7.25" y="12.75" width="3.75" height="3.75" rx="0.75" fill="currentColor" stroke="none" />
                </svg>
            </span>
            <p class="text-caption text-muted-foreground" data-testid="error-code">@yield('code')</p>
            <h1 class="text-title font-normal">@yield('headline')</h1>
            <p class="text-body text-muted-foreground">@yield('line')</p>
            <a href="{{ url('/') }}" class="rounded-full bg-primary px-6 py-3 text-body font-medium text-primary-foreground" data-testid="error-home">Back to my notebooks</a>
        </main>
    </body>
</html>
