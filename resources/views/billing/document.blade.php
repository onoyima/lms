<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $receipt ? 'Receipt' : 'Invoice' }} {{ $invoice->number }}</title>
<style>
 body{font-family:'DejaVu Sans',sans-serif;font-size:11px;color:#1d2433;margin:0}
 .bar{height:6px;background:{{ $brand }}}
 .wrap{padding:28px 36px}
 table{width:100%;border-collapse:collapse}
 .head td{vertical-align:top}
 h1{font-size:22px;margin:0;color:{{ $brand }};letter-spacing:.5px}
 .muted{color:#667085}
 .box{border:1px solid #e4e7ec;border-radius:6px;padding:10px 12px}
 .lines th{background:#f2f4f7;text-align:left;padding:7px 8px;font-size:10px;text-transform:uppercase;color:#475467}
 .lines td{padding:8px;border-bottom:1px solid #eaecf0}
 .r{text-align:right}
 .total td{font-weight:bold;font-size:13px;border-top:2px solid {{ $brand }}}
 .stamp{display:inline-block;border:2px solid #12805c;color:#12805c;padding:4px 10px;border-radius:4px;font-weight:bold;letter-spacing:1px}
 .due{display:inline-block;border:2px solid #b54708;color:#b54708;padding:4px 10px;border-radius:4px;font-weight:bold;letter-spacing:1px}
 .foot{margin-top:28px;font-size:9.5px;color:#667085}
</style></head>
<body>
<div class="bar"></div>
<div class="wrap">
<table class="head"><tr>
 <td style="width:60%">
  @if($logo)<img src="{{ $logo }}" style="max-height:56px;max-width:200px;margin-bottom:8px"><br>@endif
  <strong style="font-size:14px">{{ $institution->name }}</strong><br>
  @if($institution->contact_name)<span class="muted">{{ $institution->contact_name }}</span><br>@endif
  @if($institution->contact_email)<span class="muted">{{ $institution->contact_email }}</span><br>@endif
  @if($institution->contact_phone)<span class="muted">{{ $institution->contact_phone }}</span>@endif
 </td>
 <td class="r">
  <h1>{{ $receipt ? 'RECEIPT' : 'INVOICE' }}</h1>
  <div style="margin-top:6px">No. <strong>{{ $invoice->number }}</strong></div>
  <div class="muted">Issued {{ $invoice->created_at?->format('j M Y') }}</div>
  @if($receipt)<div style="margin-top:8px"><span class="stamp">PAID</span></div>
  @elseif($invoice->status === 'void')<div style="margin-top:8px"><span class="due">CANCELLED</span></div>
  @else<div style="margin-top:8px"><span class="due">DUE {{ $invoice->due_at?->format('j M Y') }}</span></div>@endif
 </td>
</tr></table>

<table style="margin-top:18px"><tr>
 <td style="width:50%;padding-right:8px"><div class="box">
  <div class="muted" style="font-size:9px;text-transform:uppercase">From</div>
  <strong>{{ $issuer['billing_name'] }}</strong><br>
  @if($issuer['billing_address']){!! nl2br(e($issuer['billing_address'])) !!}<br>@endif
  @if($issuer['billing_email']){{ $issuer['billing_email'] }}@endif
 </div></td>
 <td style="width:50%;padding-left:8px"><div class="box">
  <div class="muted" style="font-size:9px;text-transform:uppercase">Subscription</div>
  @if($plan)<strong>{{ $plan }}</strong>@if($cycle) · {{ $cycle }}@endif<br>@endif
  @if($invoice->period_start && $invoice->period_end)Valid {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}@endif
 </div></td>
</tr></table>

<table class="lines" style="margin-top:18px">
 <thead><tr><th>Description</th><th class="r" style="width:140px">Amount</th></tr></thead>
 <tbody>
 @foreach(($invoice->lines ?? []) as $line)
  <tr><td>{{ $line['description'] ?? '' }}</td><td class="r">{{ $money((int) ($line['amount'] ?? 0)) }}</td></tr>
 @endforeach
 <tr class="total"><td class="r">{{ $receipt ? 'Total paid' : 'Total due' }}</td><td class="r">{{ $money((int) ($receipt ? $payment->amount : $invoice->amount)) }}</td></tr>
 </tbody>
</table>

@if($receipt)
<div class="box" style="margin-top:18px">
 <div class="muted" style="font-size:9px;text-transform:uppercase">Payment</div>
 Reference <strong>{{ $payment->reference }}</strong> · {{ ucwords(str_replace('_', ' ', $payment->provider)) }} · {{ $payment->paid_at?->format('j M Y, H:i') }}
</div>
@endif

<div class="foot">
 {{ $receipt ? 'This receipt confirms the payment above.' : 'Pay online from the Billing page, or by bank transfer quoting '.$invoice->number.'.' }}
 Generated {{ now()->format('j M Y, H:i') }} by {{ $issuer['billing_name'] }}.
</div>
</div>
</body></html>
