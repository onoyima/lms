<!DOCTYPE html>
@php($platformBrand = \App\Support\Platform\PlatformSettings::brand())
@php($brand = \App\Http\Middleware\HandleInertiaRequests::showsPlatform(request()) ? null : tenant()?->brandPayload())
@php($look = $brand ?? $platformBrand)
@php($theme = in_array($look['theme'] ?? null, ['sapphire', 'forest', 'plum'], true) ? $look['theme'] : 'sapphire')
@php($wash = ($look['background_mode'] ?? 'theme') === 'none' ? 'none' : (! empty($look['background_url']) ? 'url('.str_replace(['(', ')', ' ', '"', "'"], '', $look['background_url']).')' : null))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}" @if ($wash) style="--wash-image: {{ $wash }}" @endif @class(['dark' => ($appearance ?? 'system') == 'dark'])>
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
                background-color: {{ ['sapphire' => '#f2f5fb', 'forest' => '#f1f5f2', 'plum' => '#f4f2fb'][$theme] }};
            }

            html.dark {
                background-color: #0a0f1e;
            }
        </style>

        @if ($look['favicon_url'] ?? null)
            <link rel="icon" href="{{ $look['favicon_url'] }}">
        @else
            <link rel="icon" href="/favicon.ico" sizes="any">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @endif
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- The theme sets the interface colours; the institution's own colour is kept for its crest --}}
        <style>:root { --inst: {{ $look['brand'] }}; --inst-foreground: {{ $look['brand_foreground'] }};@if ($look['accent'] ?? null) --brand-2: {{ $look['accent'] }}; --brand-2-foreground: {{ $look['accent_foreground'] }};@endif }</style>

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
