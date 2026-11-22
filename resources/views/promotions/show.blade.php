@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('branding/menu-promotions.css').'?v=3' }}">
<div class="app-shell" x-data="{ navOpen: false }">
    <x-sidebar :business="$business" mode="promotions"/>
    <main class="main promo-page" x-data>
        <a class="promo-back" href="{{ route('promotions.edit', $business) }}">← Promotions</a>
        <header class="promo-page-heading">
            <div>
                <h1>{{ $promotion->name }}</h1>
                <p>{{ $promotion->percent }}% off · {{ $promotion->all_items ? 'All menu items' : $products->count().' selected items' }}</p>
            </div>
            <span class="promo-status promo-status-{{ $promotion->status() }}">{{ ucfirst($promotion->status()) }}</span>
        </header>
        @if(session('promo_saved'))<p role="status">{{ session('promo_saved') }}</p>@endif
        <div class="promo-detail-grid">
            <section class="card promo-detail-block">
                <h2>Schedule</h2>
                @include('promotions.schedule')
                <p class="muted">{{ config('app.timezone') }}</p>
            </section>
            <section class="card promo-detail-block">
                <h2>Eligible items ({{ $products->count() }})</h2>
                <ul class="menu-promo-items">
                    @forelse($products as $product)
                        @php($saving = $promotion->unitSaving($product->price))
                        <li class="menu-promo-item">
                            <strong>{{ $product->name }}</strong>
                            <div>Regular ₱{{ number_format($product->price / 100, 2) }}</div>
                            <div class="menu-promo-saving">Save ₱{{ number_format($saving / 100, 2) }}</div>
                            <div class="menu-promo-final">Promo price ₱{{ number_format(($product->price - $saving) / 100, 2) }}</div>
                        </li>
                    @empty
                        <li>No active menu items are eligible.</li>
                    @endforelse
                </ul>
            </section>
        </div>
        <div class="promo-page-actions">
            <a class="btn primary" href="{{ route('promotions.configure', [$business, $promotion]) }}">Edit promotion</a>
            <button type="button" class="btn promo-danger" aria-haspopup="dialog" x-on:click="$refs.deletePromotion.showModal()">Delete promotion</button>
        </div>
        <dialog class="menu-promo-dialog promo-delete-dialog" x-ref="deletePromotion" aria-labelledby="delete-promo-heading">
            <h2 id="delete-promo-heading">Delete {{ $promotion->name }}?</h2>
            <p>This removes the offer from your promotions. Past orders keep their prices.</p>
            <form method="post" action="{{ route('promotions.destroy', [$business, $promotion]) }}" class="promo-page-actions">
                @csrf
                @method('DELETE')
                <button class="btn" type="button" autofocus x-on:click="$refs.deletePromotion.close()">Cancel</button>
                <button class="btn promo-danger" type="submit">Delete promotion</button>
            </form>
        </dialog>
    </main>
</div>
@endsection
