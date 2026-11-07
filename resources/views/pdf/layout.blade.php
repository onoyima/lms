<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $title ?? 'Document' }}</title>
<style>
  @page { margin: 22mm 16mm 18mm 16mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #0b0d10; }
  header { position: fixed; top: -16mm; left: 0; right: 0; border-bottom: 1px solid #d0d5dd; padding-bottom: 4px; }
  header .inst { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: {{ $tenant?->brand_color ?? '#10b981' }}; font-weight: bold; }
  footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #667085; }
  footer .page:after { content: counter(page); }
  h1 { font-size: 15pt; margin: 0 0 2mm; } h2 { font-size: 11pt; margin: 6mm 0 2mm; }
  .muted { color: #667085; } .right { text-align: right; } .mono { font-family: 'DejaVu Sans Mono', monospace; }
  table { width: 100%; border-collapse: collapse; margin-top: 3mm; }
  th { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .5px; color: #475467; border-bottom: 1.2px solid #0b0d10; padding: 3px 4px; }
  td { border-bottom: 0.5px solid #e4e7ec; padding: 3px 4px; vertical-align: top; }
  .box { border: 0.8px solid #d0d5dd; border-radius: 4px; padding: 3mm; margin-top: 3mm; }
  .sig { margin-top: 16mm; width: 45%; display: inline-block; border-top: 0.8px solid #98a2b3; padding-top: 1mm; font-size: 8pt; color: #667085; margin-right: 8%; }
</style></head>
<body>
<header><span class="inst">{{ $tenant?->name }}</span></header>
<footer>{{ $footer ?? '' }} <span style="float:right">Generated {{ now()->format('j M Y H:i') }} · page <span class="page"></span></span></footer>
@yield('content')
</body></html>
