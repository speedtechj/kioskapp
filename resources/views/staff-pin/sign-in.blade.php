@extends('layouts.app', ['title' => 'Cashier sign-in'])
@section('content')
<link rel="stylesheet" href="{{ asset('branding/staff-pin.css') }}">
<link rel="stylesheet" href="{{ asset('branding/cashier-roomy.css') }}">
<main class="pin-page cashier-sign-in-page">
    <section class="pin-card">
        <div class="pin-brand">
            <img src="{{ $kiosk->cashierIcon(192) }}" width="64" height="64" alt="">
            <strong>Gather<span>.</span></strong>
            <h2>{{ $business->name }}</h2>
            <p class="pin-branch">{{ $kiosk->name }}</p>
        </div>
        <div class="pin-content">
            <div class="pin-heading">
                <h1>Cashier sign-in</h1>
                <button type="button" class="btn" data-cashier-install>Install Cashier POS</button>
            </div>
            @if($cashiers->isEmpty())
                <p>No cashiers are set up at this branch. Ask your manager to set a PIN.</p>
            @else
                @php
                    $selected = $cashiers->firstWhere('id', (int) old('cashier'));
                @endphp
                <form method="post" action="{{ request()->getRequestUri() }}"
                    x-data="{ ready: false, cashier: @js((string) ($selected?->id ?? '')), name: @js($selected?->name ?? 'Choose cashier') }"
                    x-init="ready = true"
                    @submit="if (ready && !cashier) { $event.preventDefault(); if (!$refs.picker.open) $refs.picker.showModal() }">
                    @csrf
                    <label x-show="!ready">Select cashier
                        <select name="cashier" required :required="!ready" x-model="cashier">
                            <option value="">Choose cashier</option>
                            @foreach($cashiers as $cashier)
                                <option value="{{ $cashier->id }}" @selected($selected?->id === $cashier->id)>{{ $cashier->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="cashier-picker" x-show="ready" x-cloak>
                        <span class="cashier-picker-label">Cashier</span>
                        <button type="button" class="cashier-picker-trigger" aria-haspopup="dialog" aria-controls="cashier-picker-dialog"
                            @click="if (!$refs.picker.open) $refs.picker.showModal()">
                            <x-g-icon name="user"/><span x-text="name">Choose cashier</span><span aria-hidden="true">⌄</span>
                        </button>
                        <dialog id="cashier-picker-dialog" class="gather-dialog gd-content cashier-picker-dialog" x-ref="picker" aria-labelledby="cashier-picker-title">
                            <div class="section-line">
                                <h2 id="cashier-picker-title">Choose cashier</h2>
                                <button type="button" class="icon-btn" @click="$refs.picker.close()" aria-label="Close cashier picker"><x-g-icon name="x-mark"/></button>
                            </div>
                            <div class="cashier-picker-list">
                                @foreach($cashiers as $cashier)
                                    <button type="button" class="cashier-choice"
                                        :aria-pressed="cashier === @js((string) $cashier->id)"
                                        @click="if (cashier !== @js((string) $cashier->id)) $dispatch('cashier-selected'); cashier = @js((string) $cashier->id); name = @js($cashier->name); $refs.picker.close()">
                                        <span class="cashier-choice-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($cashier->name, 0, 1)) }}</span>
                                        <span>{{ $cashier->name }}</span>
                                        <span x-show="cashier === @js((string) $cashier->id)" aria-hidden="true">✓</span>
                                    </button>
                                @endforeach
                            </div>
                        </dialog>
                    </div>
                    <x-staff-pin-entry/>
                    @foreach($errors->all() as $error)<p role="alert" class="error">{{ $error }}</p>@endforeach
                    <button type="submit" class="btn primary wide cashier-open-pos">Open POS</button>
                </form>
            @endif
        </div>
    </section>
</main>
@endsection
