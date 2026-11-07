@extends('bridges.vuna.layout')
@section('head')
    <meta http-equiv="refresh" content="0;url={{ $url }}">
@endsection
@section('title', 'Signed in')
@section('content')
    <h1>Signed in</h1>
    <p class="lead">Taking you back now.</p>
    <a class="button" href="{{ $url }}">Continue</a>
@endsection
