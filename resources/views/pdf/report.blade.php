@extends('pdf.layout')
@section('content')
<h1>{{ $title }}</h1>
<p class="muted">{{ $subtitle ?? '' }}</p>
<table>
  <thead><tr>@foreach ($columns as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
  <tbody>@foreach ($rows as $r)<tr>@foreach (array_values((array) $r) as $v)<td>{{ \App\Services\Reports\Exporter::safe($v) }}</td>@endforeach</tr>@endforeach</tbody>
</table>
@if (($truncated ?? false))<p class="muted">Only the first {{ count($rows) }} rows are in the PDF; use Excel or CSV for everything.</p>@endif
@endsection
