@extends('pdf.layout')
@section('content')
<h1>Receipt</h1>
<p class="muted">Order {{ $order->number }} · paid {{ $order->paid_at?->format('j M Y H:i') }}</p>
<table style="margin-top:2mm">
  <tr><td style="border:0;width:22%" class="muted">Paid by</td><td style="border:0"><strong>{{ $order->user->name }}</strong> ({{ $order->user->email }})</td></tr>
  <tr><td style="border:0" class="muted">Payment</td><td style="border:0">{{ ['paystack' => 'Online (Paystack)', 'voucher' => 'Voucher', 'free' => 'Free'][$order->provider] ?? $order->provider }}</td></tr>
</table>
<table>
  <thead><tr><th>Item</th><th class="right">Price ({{ $order->currency }})</th></tr></thead>
  <tbody>
    @foreach ($order->items as $i)<tr><td>{{ $i->title }}</td><td class="right">{{ number_format($i->price / 100, 2) }}</td></tr>@endforeach
    @if ($order->discount > 0)<tr><td class="right">Discount</td><td class="right">−{{ number_format($order->discount / 100, 2) }}</td></tr>@endif
    <tr><td class="right"><strong>Total paid</strong></td><td class="right"><strong>{{ $order->currency }} {{ number_format($order->total / 100, 2) }}</strong></td></tr>
  </tbody>
</table>
<div class="box">Receipt code <span class="mono"><strong>{{ $order->receipt_code }}</strong></span></div>
@endsection
