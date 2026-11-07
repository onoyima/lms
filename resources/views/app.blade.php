<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #ffffff;
            }

            html.dark {
                background-color: #0b0d10;
            }
        </style>

        @php($platformBrand = \App\Support\Platform\PlatformSettings::brand())
        @php($brand = \App\Http\Middleware\HandleInertiaRequests::showsPlatform(request()) ? null : tenant()?->brandPayload())
        @php($look = $brand ?? $platformBrand)
        @if ($look['favicon_url'] ?? null)
            <link rel="icon" href="{{ $look['favicon_url'] }}">
        @else
            <link rel="icon" href="/favicon.ico" sizes="any">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @endif
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <style>:root { --brand: {{ $look['brand'] }}; --brand-foreground: {{ $look['brand_foreground'] }};@if ($look['accent'] ?? null) --brand-2: {{ $look['accent'] }}; --brand-2-foreground: {{ $look['accent_foreground'] }};@endif }</style>

        {{-- Equations: local MathJax 3 (works offline on a campus LAN); see resources/js/components/rich-content.tsx --}}
        <script nonce="{{ Vite::cspNonce() }}">
            window.MathJax = {
                loader: { load: [] },
                tex: { inlineMath: [['$', '$'], ['\\(', '\\)']], displayMath: [['$$', '$$'], ['\\[', '\\]']], processEscapes: true, packages: { '[+]': ['mhchem'] } },
                svg: { fontCache: 'global' },
                options: { enableMenu: false },
                startup: { typeset: false }
            };
        </script>
        <script defer id="MathJax-script" nonce="{{ Vite::cspNonce() }}" src="/vendor/mathjax/tex-svg-full.js"></script>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ $look['short_name'] }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
