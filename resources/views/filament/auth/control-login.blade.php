<div class="gather-control-login">
    <link rel="stylesheet" href="{{ asset('branding/control-login.css') }}">

    <link rel="stylesheet" href="{{ asset('branding/mobile-sign-in.css') }}">

    <picture class="gather-login-photo" aria-hidden="true">
        <source media="(min-width: 800px)" srcset="{{ asset('branding/control-counter.webp') }}">
        <img src="{{ asset('branding/control-counter-mobile.webp') }}" alt="" width="900" height="1000" fetchpriority="high">
    </picture>

    <div class="gather-login-form">
        <x-filament-panels::page.simple>
            {{ $this->content }}
        </x-filament-panels::page.simple>
    </div>
</div>
