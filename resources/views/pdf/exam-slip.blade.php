@extends('pdf.layout')
@section('content')
@foreach ($slips as $i => $s)
<div @if($i > 0) style="page-break-before: always" @endif>
  <h1>Examination slip</h1>
  <p class="muted">{{ $s['exam']->course?->code }} {{ $s['exam']->course?->title }}@if($s['exam']->kind !== 'regular') · {{ ucfirst($s['exam']->kind) }}@endif</p>
  <table style="margin-top:3mm">
    <tr><td style="border:0;width:24%" class="muted">Candidate</td><td style="border:0"><strong>{{ $s['candidate']->user->name }}</strong></td><td style="border:0;width:20%" class="muted">Matric number</td><td style="border:0" class="mono">{{ $s['candidate']->user->identifier }}</td></tr>
    <tr><td style="border:0" class="muted">Exam</td><td style="border:0" colspan="3"><strong>{{ $s['exam']->title }}</strong></td></tr>
    <tr><td style="border:0" class="muted">Date and time</td><td style="border:0"><strong>{{ $s['session']->starts_at->format('l j F Y, H:i') }}</strong></td><td style="border:0" class="muted">Duration</td><td style="border:0">{{ $s['minutes'] }} minutes</td></tr>
    <tr><td style="border:0" class="muted">Where</td><td style="border:0">{{ $s['session']->mode === 'remote' ? 'Online (remote, proctored)' : ($s['session']->venue?->name ?? 'Venue to be announced') }}{{ $s['session']->venue?->campus ? ', '.$s['session']->venue->campus : '' }}</td><td style="border:0" class="muted">Seat</td><td style="border:0" class="mono">{{ $s['candidate']->seat?->label ?? '—' }}</td></tr>
    <tr><td style="border:0" class="muted">Sitting</td><td style="border:0">{{ $s['session']->name ?? '—' }}</td><td style="border:0" class="muted">Entry closes</td><td style="border:0">{{ $s['session']->starts_at->copy()->addMinutes($s['session']->late_entry_minutes)->format('H:i') }}</td></tr>
  </table>
  @if ($s['candidate']->extra_time_minutes > 0)
  <div class="box">Approved extra time of {{ $s['candidate']->extra_time_minutes }} minutes is included above.</div>
  @endif
  <h2>Rules</h2>
  <ul>
    <li>Arrive 30 minutes early with this slip and your institution ID card. You will not be admitted after entry closes.</li>
    <li>Phones, smart watches, notes and other devices are not allowed unless the exam instructions say so.</li>
    @if ($s['session']->mode === 'remote')
    <li>Use a computer with a working camera and microphone. Run the system check on the exam page before the start time.</li>
    @else
    <li>Sit only at your assigned seat. Your access PIN is given to you in the hall.</li>
    @endif
    <li>Any attempt at malpractice is recorded and reported to the exams office.</li>
  </ul>
  <div class="box">Verification code <span class="mono"><strong>{{ $s['code'] }}</strong></span> — {{ $s['verifyUrl'] }}</div>
  <span class="sig">Candidate</span><span class="sig">Invigilator</span>
</div>
@endforeach
@endsection
