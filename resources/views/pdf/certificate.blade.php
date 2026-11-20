<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
  @page { margin: 0; }
  body { margin: 0; font-family: 'DejaVu Serif', serif; color: #10202a; }
  .frame { position: absolute; top: 9mm; left: 9mm; right: 9mm; bottom: 9mm; border: 2.5mm solid {{ $template->colour }}; }
  .inner { position: absolute; top: 13mm; left: 13mm; right: 13mm; bottom: 13mm; border: 0.4mm solid {{ $template->colour }}; text-align: center; padding: 14mm 22mm 0; }
  .inst { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; letter-spacing: 3px; text-transform: uppercase; color: {{ $template->colour }}; font-weight: bold; }
  h1 { font-size: 30pt; margin: 9mm 0 3mm; font-weight: normal; }
  .lead { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #5b6770; }
  .name { font-size: 26pt; margin: 4mm 0 5mm; border-bottom: 0.4mm solid #c9d2d8; display: inline-block; padding: 0 12mm 2mm; }
  .body { font-size: 12.5pt; line-height: 1.55; margin: 0 auto; width: 78%; }
  .sig { position: absolute; bottom: 22mm; left: 30mm; width: 70mm; border-top: 0.3mm solid #5b6770; padding-top: 1.5mm; font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #5b6770; text-align: left; }
  .verify { position: absolute; bottom: 22mm; right: 30mm; width: 90mm; font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: #5b6770; text-align: right; }
  .code { font-family: 'DejaVu Sans Mono', monospace; font-size: 10pt; color: #10202a; letter-spacing: 1px; }
</style></head>
<body>
<div class="frame"></div>
<div class="inner">
  <div class="inst">{{ $tenant?->name }}</div>
  <h1>{{ $template->heading }}</h1>
  <div class="lead">This is to certify that</div>
  <div class="name">{{ $holder->name }}</div>
  <div class="body">{{ $body }}</div>
</div>
@if ($template->signatory_name)
  <div class="sig"><strong style="color:#10202a">{{ $template->signatory_name }}</strong><br>{{ $template->signatory_title }}</div>
@endif
<div class="verify">Issued {{ \Illuminate\Support\Carbon::parse($award->created_at)->format('j F Y') }}<br>Check this certificate at {{ $verifyUrl }}<br><span class="code">{{ $award->code }}</span></div>
</body></html>
