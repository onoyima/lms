@php
    // Error pages follow the institution's chosen theme, but must still render when the database is down.
    try {
        $look = \App\Http\Middleware\HandleInertiaRequests::showsPlatform(request()) ? \App\Support\Platform\PlatformSettings::brand() : (tenant()?->brandPayload() ?? \App\Support\Platform\PlatformSettings::brand());
    } catch (\Throwable) {
        $look = [];
    }
    $theme = in_array($look['theme'] ?? null, ['sapphire', 'forest', 'plum', 'midnight'], true) ? $look['theme'] : 'sapphire';
    $c = [
        'sapphire' => ['#0b1f4b', '#132e6b', '#3d7bff', '#ffffff', '#3d7bff', '#22c3a6', '#8b5cf6', '#f2f5fb'],
        'forest' => ['#08362b', '#0e4a3b', '#d9a43a', '#1b1405', '#1f9d6b', '#d9a43a', '#3e8ed0', '#f1f5f2'],
        'plum' => ['#1e1240', '#2e1d5e', '#8b6cff', '#ffffff', '#8b6cff', '#4cc3ff', '#ff7aa8', '#f4f2fb'],
        'midnight' => ['#05070b', '#10141d', '#ff6a13', '#1a0a00', '#ff6a13', '#ffb347', '#c2410c', '#07090e'],
    ][$theme];
    $code = trim($__env->yieldContent('code'));
    $help = [
        '401' => 'Sign in to continue.',
        '403' => 'Your account does not have access to this page. If you think it should, ask your institution\'s administrator.',
        '404' => 'The address may be mistyped, or the page has moved.',
        '419' => 'The page was open too long. Go back, reload and try again.',
        '429' => 'Too many tries in a short time. Wait a minute and try again.',
        '500' => 'Something went wrong on our side. It has been recorded; try again in a moment.',
        '503' => 'The system is being updated. Exams already under way keep their answers. Try again shortly.',
    ][$code] ?? '';
    // a specific reason (for example "not part of your subscription") replaces the general advice
    if (trim($__env->yieldContent('message')) !== trim($__env->yieldContent('title'))) {
        $help = '';
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $look['name'] ?? '' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; font-family: Figtree, 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #fff; background: radial-gradient(520px 380px at 12% 12%, {{ $c[4] }}55, transparent 70%), radial-gradient(480px 340px at 88% 20%, {{ $c[6] }}44, transparent 70%),
            radial-gradient(520px 380px at 60% 100%, {{ $c[5] }}3a, transparent 70%), linear-gradient(150deg, {{ $c[0] }}, {{ $c[1] }}); }
        main { width: 100%; max-width: 30rem; border-radius: 24px; padding: 32px; background: rgb(255 255 255 / .06); border: 1px solid rgb(255 255 255 / .12); backdrop-filter: blur(16px); }
        .code { font-size: 64px; font-weight: 700; letter-spacing: -.04em; line-height: 1; margin: 0; }
        h1 { font-size: 22px; margin: 16px 0 8px; font-weight: 650; }
        p { margin: 0; color: rgb(255 255 255 / .72); line-height: 1.55; }
        .who { font-size: 14px; color: rgb(255 255 255 / .6); margin-bottom: 24px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 28px; }
        a { display: inline-block; padding: 10px 18px; border-radius: 12px; font-weight: 600; font-size: 15px; text-decoration: none; }
        a.primary { background: {{ $c[2] }}; color: {{ $c[3] }}; }
        a.ghost { color: #fff; border: 1px solid rgb(255 255 255 / .2); }
        a:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    @if (! empty($look['name']))<p class="who">{{ $look['name'] }}</p>@endif
    <p class="code">@yield('code')</p>
    <h1>@yield('message')</h1>
    @if ($help)<p>{{ $help }}</p>@endif
    <div class="actions">
        <a class="primary" href="{{ url('/') }}">Go to the home page</a>
        <a class="ghost" href="{{ url()->previous() }}">Go back</a>
    </div>
</main>
</body>
</html>
