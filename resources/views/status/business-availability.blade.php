<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ $business->name }}</title>
    <link rel="stylesheet" href="{{ asset('branding/business-availability.css') }}">
</head>
<body class="availability-page">
    <main class="availability-card">
        <div class="availability-brand">
            @if($logo = $business->logoUrl())
                <img src="{{ $logo }}" width="48" height="48" alt="">
            @else
                <img src="{{ asset('branding/bowl-192.png') }}" width="48" height="48" alt="">
            @endif
            <span>{{ $business->name }}</span>
        </div>
        <div class="availability-symbol">
            <x-g-icon :name="$comingSoon ? 'building-storefront' : 'wrench-screwdriver'"/>
        </div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @if($retry)
            <a class="availability-retry" href="{{ request()->fullUrl() }}">Try again</a>
        @endif
    </main>
</body>
</html>
