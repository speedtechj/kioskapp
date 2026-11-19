@extends('layouts.app', ['title' => 'Change my PIN'])
@section('content')
<link rel="stylesheet" href="{{ asset('branding/staff-pin.css') }}">
<main class="pin-page">
    <section class="pin-card">
        <div class="pin-brand"><strong>Gather<span>.</span></strong><h2>{{ auth()->user()->name }}</h2><p>{{ $kiosk->name }}</p></div>
        <div class="pin-content">
            <h1>Change my PIN</h1>
            <x-change-cashier-pin-form :business="$business" :kiosk="$kiosk"/>
        </div>
    </section>
</main>
@endsection
