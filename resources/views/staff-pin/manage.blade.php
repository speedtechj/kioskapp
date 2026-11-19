@extends('layouts.app', ['title' => 'Cashier access'])
@section('content')
<link rel="stylesheet" href="{{ asset('branding/staff-pin.css') }}">
<main class="pin-page">
    <section class="pin-card">
        <div class="pin-brand"><strong>Gather<span>.</span></strong><h2>{{ $business->name }}</h2><p>{{ $kiosk->name }}</p></div>
        <div class="pin-content">
            <a class="text-link" href="{{ route('pos', [$business, $kiosk]) }}">Back to POS</a>
            <h1>Cashier access</h1>
            <label>Branch<select @change="window.location.assign($event.target.value)" x-data>
                @foreach($branches as $branch)
                    <option value="{{ route('cashier.access', [$business, $branch]) }}" @selected($branch->is($kiosk))>{{ $branch->name }}</option>
                @endforeach
            </select></label>
            <div class="pin-qr">{!! $qr !!}</div>
            <label>POS sign-in link <input readonly value="{{ $link }}" id="cashier-sign-in-link"></label>
            <div class="pin-actions" x-data="{ copied: false }">
                <a class="btn primary" href="{{ route('cashier.access', [$business, $kiosk, 'download' => 'qr']) }}">Download QR</a>
                <button class="btn" type="button" @click="navigator.clipboard?.writeText(document.getElementById('cashier-sign-in-link').value).then(() => copied = true)" x-text="copied ? 'Copied' : 'Copy POS link'">Copy POS link</button>
            </div>
            @if(session('pin_status'))<p role="status">{{ session('pin_status') }}</p>@endif
            <form method="post" enctype="multipart/form-data" x-data="{ faviconPreview: '', iconPreview: '' }" action="{{ route('cashier.branding.save', [$business, $kiosk]) }}">
                @csrf
                @method('PUT')
                <h2>Cashier POS branding</h2>
                <label>Browser favicon
                    <img width="32" height="32" src="{{ $kiosk->cashierIcon(32) }}" data-original="{{ $kiosk->cashierIcon(32) }}" :src="faviconPreview || $el.dataset.original" alt="Current favicon">
                    <input type="file" name="favicon" accept="image/png" @change="if (faviconPreview) URL.revokeObjectURL(faviconPreview); faviconPreview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : ''">
                    <small>Square PNG, at least 32 × 32 px.</small>
                </label>
                <label>Home screen icon
                    <img width="64" height="64" src="{{ $kiosk->cashierIcon(192) }}" data-original="{{ $kiosk->cashierIcon(192) }}" :src="iconPreview || $el.dataset.original" alt="Current Home Screen icon">
                    <input type="file" name="icon" accept="image/png" @change="if (iconPreview) URL.revokeObjectURL(iconPreview); iconPreview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : ''">
                    <small>Square PNG, at least 512 × 512 px.</small>
                </label>
                @error('favicon')<p class="error" role="alert">{{ $message }}</p>@enderror
                @error('icon')<p class="error" role="alert">{{ $message }}</p>@enderror
                <button type="submit" class="btn primary">Save branding</button>
            </form>
            <form method="post" action="{{ route('cashier.pin.reset', [$business, $kiosk]) }}">
                @csrf
                <h2>Set or reset a PIN</h2>
                <label>Staff member<select name="staff" required>
                    <option value="">Choose a staff member</option>
                    @foreach($staff as $person)
                        @if($role === 'owner' || $person->pivot->role === 'cashier')
                            <option value="{{ $person->id }}">{{ $person->name }} · {{ ucfirst($person->pivot->role) }}</option>
                        @endif
                    @endforeach
                </select></label>
                <label>New PIN<input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required></label>
                <label>Confirm new PIN<input type="password" name="pin_confirmation" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required></label>
                @foreach($errors->all() as $error)<p role="alert" class="error">{{ $error }}</p>@endforeach
                <button type="submit" class="btn primary">Save PIN</button>
            </form>
        </div>
    </section>
</main>
@endsection
