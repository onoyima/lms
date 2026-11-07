@extends('bridges.vuna.layout')
@section('title', 'Continue')
@section('content')
    <h1>Continue to {{ $client }}?</h1>
    <p class="lead">You are already signed in to the portal in this browser.</p>
    <div class="person">
        <strong>{{ $person['name'] ?: ($person['identifier'] ?? '') }}</strong>
        <span>{{ $kind }}@if (! empty($person['identifier'])) · {{ $person['identifier'] }}@endif</span>
    </div>
    <form method="post" action="{{ route('vuna-bridge.login') }}">
        @csrf
        <input type="hidden" name="request" value="{{ $sealed }}">
        <button type="submit" name="action" value="continue">Continue as {{ $person['first_name'] ?? $person['name'] }}</button>
        <button type="submit" name="action" value="switch" class="secondary">Use a different account</button>
    </form>
@endsection
