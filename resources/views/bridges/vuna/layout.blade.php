<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @yield('head')
    <title>@yield('title', 'Sign in') · {{ $institution }}</title>
    <style>
        :root { --ink:#13202a; --ink-2:#4b5963; --line:#d9e0e4; --paper:#f4f6f5; --card:#fff; --brand:#0b6b4f; --brand-ink:#fff; --bad:#9b1c1c; --bad-bg:#fdf0f0; --focus:#0b6b4f; }
        @media (prefers-color-scheme: dark) { :root { --ink:#e8eef1; --ink-2:#a9b6bd; --line:#2c3a42; --paper:#0f171c; --card:#16222a; --brand:#2fb487; --brand-ink:#04140e; --bad:#ffb4b4; --bad-bg:#3a1717; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px 16px; background:var(--paper); color:var(--ink); font:16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width:100%; max-width:420px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:28px; }
        .eyebrow { margin:0 0 4px; font-size:12px; letter-spacing:.12em; text-transform:uppercase; color:var(--ink-2); }
        h1 { margin:0 0 6px; font-size:22px; line-height:1.25; }
        p.lead { margin:0 0 20px; color:var(--ink-2); font-size:14px; }
        label { display:block; font-size:14px; font-weight:600; margin:14px 0 6px; }
        input[type=text], input[type=password] { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:10px; background:transparent; color:inherit; font:inherit; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline:3px solid var(--focus); outline-offset:2px; }
        fieldset { border:0; margin:0; padding:0; }
        legend { font-size:14px; font-weight:600; margin-bottom:6px; padding:0; }
        .types { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; }
        .types label { margin:0; font-weight:500; display:flex; align-items:center; justify-content:center; gap:6px; padding:9px 6px; border:1px solid var(--line); border-radius:10px; cursor:pointer; font-size:14px; }
        .types input { accent-color:var(--brand); }
        .types label:has(input:checked) { border-color:var(--brand); box-shadow:inset 0 0 0 1px var(--brand); }
        .hint { font-size:12px; color:var(--ink-2); margin-top:6px; }
        button, .button { display:inline-flex; justify-content:center; align-items:center; width:100%; margin-top:20px; padding:12px; border:0; border-radius:10px; background:var(--brand); color:var(--brand-ink); font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
        button.secondary { background:transparent; color:var(--ink); border:1px solid var(--line); margin-top:10px; }
        .error { margin:0 0 14px; padding:10px 12px; border-radius:10px; background:var(--bad-bg); color:var(--bad); font-size:14px; }
        .person { padding:12px 14px; border:1px solid var(--line); border-radius:12px; }
        .person strong { display:block; }
        .person span { font-size:13px; color:var(--ink-2); }
        footer { margin-top:16px; text-align:center; font-size:12px; color:var(--ink-2); }
    </style>
</head>
<body>
<main>
    <div class="card">
        <p class="eyebrow">{{ $institution }}</p>
        @yield('content')
    </div>
    <footer>Your password is checked by the university portal and never shared with other applications.</footer>
</main>
</body>
</html>
