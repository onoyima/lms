@extends('pdf.layout')
@section('content')
<h1>{{ $title }}</h1>
<p class="muted">{{ $semester }}</p>
<table style="margin-top:2mm">
  <tr><td style="border:0;width:25%" class="muted">Name</td><td style="border:0"><strong>{{ $holder->name }}</strong></td><td style="border:0;width:20%" class="muted">Matric number</td><td style="border:0" class="mono">{{ $holder->identifier }}</td></tr>
  <tr><td style="border:0" class="muted">Programme</td><td style="border:0">{{ $profile->programme ?? '—' }}</td><td style="border:0" class="muted">Level</td><td style="border:0">{{ $profile->level ?? '—' }}</td></tr>
</table>
<table>
  <thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Stream</th><th class="right">Units</th></tr></thead>
  <tbody>
    @foreach ($rows as $r)<tr><td class="mono">{{ $r->code }}</td><td>{{ $r->title }}@if($r->status === 'pending') (awaiting approval)@endif</td><td>{{ $r->category }}</td><td>{{ $r->stream ?? '—' }}</td><td class="right">{{ $r->units }}</td></tr>@endforeach
    <tr><td colspan="4" class="right"><strong>Total units</strong></td><td class="right"><strong>{{ collect($rows)->sum('units') }}</strong></td></tr>
  </tbody>
</table>
<div class="box">Verification code <span class="mono"><strong>{{ $code }}</strong></span> — {{ $verifyUrl }}</div>
<span class="sig">Student</span><span class="sig">Level adviser / HOD</span>
@endsection
