@extends('bridges.vuna.layout')
@section('title', 'Sign in')
@section('content')
    <h1>Sign in to {{ $client }}</h1>
    <p class="lead">Use the same account and password as the university portal.</p>
    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif
    <form method="post" action="{{ route('vuna-bridge.login') }}" novalidate>
        @csrf
        <input type="hidden" name="request" value="{{ $sealed }}">
        <input type="hidden" name="action" value="login">
        <fieldset>
            <legend>I am</legend>
            <div class="types">
                @foreach ($types as $value => $label)
                    <label><input type="radio" name="account_type" value="{{ $value }}" @checked($type === $value) required> {{ $label }}</label>
                @endforeach
            </div>
        </fieldset>
        <label for="login">Matric number, staff number or email</label>
        <input id="login" name="login" type="text" value="{{ $login }}" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        <p class="hint">Students: matric number, registration number or email. Staff: staff number or email. Applicants: the email you applied with.</p>
        <label for="password">Portal password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
@endsection
