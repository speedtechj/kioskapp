@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('branding/mobile-sign-in.css') }}">
<script defer src="{{ asset('branding/workspace-password.js') }}"></script>
<div class="login-page gather-workspace-login">
    <section class="login-story">
        <x-brand/>
        <div><span class="eyebrow">LESS FRICTION. MORE FLAVOR.</span><h1>Good food.<br>Great service.<br><em>All connected.</em></h1><p>One thoughtful workspace for every kiosk, every order, and everyone on your team.</p></div>
        <span class="login-foot">GATHER / RESTAURANT OPERATING SYSTEM</span>
    </section>
    <main class="login-form">
        <picture class="workspace-login-photo" aria-hidden="true">
            <source media="(max-width: 799px)" srcset="{{ asset('branding/control-counter-mobile.webp') }}">
            <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="" width="750" height="320">
        </picture>
        <form action="{{ route('login') }}" method="post" class="login-card">
            @csrf
            <div class="workspace-mobile-brand"><img src="{{ asset('branding/bowl-192.png') }}" width="42" height="42" alt=""><strong>Gather</strong></div>
            <span class="orange-icon workspace-desktop-icon"><x-g-icon name="building-storefront"/></span>
            <h2>Welcome back</h2>
            <p class="muted">Sign in to your restaurant workspace.</p>
            <label for="workspace-email">Email address</label>
            <input id="workspace-email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required @error('email') aria-invalid="true" aria-describedby="workspace-email-error" @enderror>
            @error('email')<p id="workspace-email-error" class="error" role="alert">{{ $message }}</p>@enderror
            <label for="workspace-password">Password</label>
            <div class="workspace-password">
                <input id="workspace-password" type="password" name="password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="workspace-password-error" @enderror>
                <button type="button" id="workspace-password-toggle" aria-controls="workspace-password" aria-pressed="false" aria-label="Show password" hidden><x-g-icon name="eye"/></button>
            </div>
            @error('password')<p id="workspace-password-error" class="error" role="alert">{{ $message }}</p>@enderror
            <button class="btn primary wide" type="submit">Sign in to workspace</button>
        </form>
    </main>
</div>
@endsection
