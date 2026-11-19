@php
    $staffApp = request()->routeIs('login', 'home', 'workspace', 'reports.sales', 'business.settings', 'business.ai', 'audit.trail', 'pos', 'kitchen', 'payments', 'kiosk.brand');
    $cashierKiosk = request()->routeIs('pos', 'payments', 'cashier.sign-in', 'cashier.pin.edit') ? request()->route('kiosk') : null;
    $cashierKiosk = $cashierKiosk instanceof \App\Models\Kiosk ? $cashierKiosk : null;
    $brandKiosk = in_array(request()->route()?->getName(), ['menu','takeout'], true) ? request()->route('kiosk') : null;
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#242824"><meta name="description" content="Gather — a connected restaurant ordering experience"><meta name="referrer" content="same-origin"><title>{{ $brandKiosk?->app_label ?? (($title ?? 'Gather').' · Gather') }}</title><link rel="manifest" href="{{ $manifestUrl ?? ($cashierKiosk ? route('cashier.manifest', [$cashierKiosk->business, $cashierKiosk]) : null) ?? ($staffApp ? asset('staff.webmanifest') : '/manifest.webmanifest') }}"><link rel="icon" href="{{ $cashierKiosk?->cashierIcon(32) ?? $brandKiosk?->appIcon(32) ?? asset('icon.svg') }}"><link rel="apple-touch-icon" href="{{ $cashierKiosk?->cashierIcon(180) ?? $brandKiosk?->appIcon(180) ?? asset($staffApp ? 'branding/bowl-180.png' : 'icons/icon-192.png') }}">@vite(['resources/css/app.css','resources/js/app.js'])@livewireStyles
@include('partials.dialog-styles')
@if($brandKiosk)
<meta name="apple-mobile-web-app-title" content="{{ $brandKiosk->appLabel() }}">
<link rel="stylesheet" href="{{ asset('branding/studio.css') }}">
<script defer src="{{ asset('branding/install.js').'?v=3' }}"></script>
@endif
@if($staffApp)
<meta name="apple-mobile-web-app-title" content="{{ $cashierKiosk ? 'Gather POS' : 'Gather Staff' }}">
<link rel="stylesheet" href="{{ asset('branding/staff-mobile.css') }}">
<link rel="stylesheet" href="{{ asset('branding/staff-sidebar.css') }}">
@unless($cashierKiosk)<script defer src="{{ asset('branding/staff-mobile.js') }}"></script>@endunless
@unless($cashierKiosk)<script defer src="{{ asset('branding/install.js').'?v=3' }}"></script>@endunless
@endif
@if($cashierKiosk)
@unless($staffApp)<meta name="apple-mobile-web-app-title" content="Gather POS">@endunless
<link rel="stylesheet" href="{{ asset('branding/cashier-pwa.css') }}">
<script defer src="{{ asset('branding/cashier-pwa.js') }}"></script>
@endif
<link rel="stylesheet" href="{{ asset('branding/mobile-type.css') }}" media="(max-width: 760px)">
@include('partials.readable-type')
@if($brandKiosk || $staffApp)
<link rel="stylesheet" href="{{ asset('branding/mobile-menu.css') }}">
@endif
@if(!empty($aiSettings) || $brandKiosk)<link rel="stylesheet" href="{{ asset('branding/ai-settings.css') }}">@endif
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.interceptRequest(({ onError }) => {
            onError(({ response, preventDefault }) => {
                if (response.headers.get('X-Gather-Business-Paused') === '1') {
                    preventDefault();
                    window.location.reload();
                }
            });
        });
    });
</script>
</head><body class="gather-type">{{ $slot ?? '' }}@yield('content')@livewireScripts<x-confirm-dialog/><script defer src="{{ asset('gather-dialogs-v1.js') }}"></script>
@if($brandKiosk)
@include('branding.install',['kiosk'=>$brandKiosk])
@endif
@if($cashierKiosk)
@include('staff-pin.install', ['kiosk' => $cashierKiosk])
@endif
@if($staffApp && auth()->check() && !$cashierKiosk)
@include('branding.install', ['kiosk' => null])
@endif
</body></html>