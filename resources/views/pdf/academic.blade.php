@extends('pdf.layout')
@section('content')
<h1>{{ $title }}</h1>
<table style="margin-top:2mm">
  <tr><td style="border:0;width:30%" class="muted">Name</td><td style="border:0"><strong>{{ $holder->name }}</strong></td><td style="border:0;width:25%" class="muted">Matric number</td><td style="border:0" class="mono">{{ $holder->identifier }}</td></tr>
  <tr><td style="border:0" class="muted">Programme</td><td style="border:0">{{ $profile->programme ?? '—' }}</td><td style="border:0" class="muted">Level / status</td><td style="border:0">{{ $profile->level ?? '—' }} · {{ $profile->academic_status ?? '—' }}</td></tr>
</table>
@foreach ($record['semesters'] as $s)
  <h2>{{ $s['label'] }}</h2>
  <table>
    <thead><tr><th style="width:14%">Code</th><th>Title</th><th class="right" style="width:8%">Units</th><th class="right" style="width:9%">Score</th><th class="right" style="width:8%">Grade</th><th class="right" style="width:10%">Points</th></tr></thead>
    <tbody>
      @foreach ($s['courses'] as $c)<tr><td class="mono">{{ $c['code'] }}</td><td>{{ $c['title'] }}</td><td class="right">{{ $c['units'] }}</td><td class="right">{{ $c['total'] }}</td><td class="right"><strong>{{ $c['grade'] }}</strong></td><td class="right">{{ $c['points'] }}</td></tr>@endforeach
      <tr><td colspan="2" class="right muted">Semester: {{ $s['units'] }} units · GPA <strong>{{ number_format((float) $s['gpa'], 2) }}</strong></td><td colspan="4" class="right muted">Cumulative GPA <strong>{{ number_format((float) $s['cgpa'], 2) }}</strong></td></tr>
    </tbody>
  </table>
@endforeach
<div class="box"><strong>Total units: {{ $record['units'] }} · CGPA: {{ number_format((float) $record['cgpa'], 2) }}</strong></div>
<div class="box">Verification code <span class="mono"><strong>{{ $code }}</strong></span> — check this document at {{ $verifyUrl }}. A document whose code does not verify, or whose details differ from the verification page, is not genuine.</div>
@if (! empty($transcript))<span class="sig">Registrar</span><span class="sig">Date and seal</span>@endif
@endsection
