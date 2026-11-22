@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('branding/menu-promotions.css').'?v=2' }}">
<div class="app-shell" x-data="{ navOpen: false }">
    <x-sidebar :business="$business" mode="promotions"/>
    <main class="main">
        <header class="topbar"><h1>Item promotion</h1></header>
        @if(session('promo_saved'))<p role="status">{{ session('promo_saved') }}</p>@endif
        @if($errors->any())
            <ul role="alert" class="error">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif

        <form class="card promo-editor" action="{{ route('promotions.save', $business) }}" method="post"
            x-data="{ allItems: @js((bool) old('all_items', $promotion?->all_items ?? true)), percent: @js((int) old('percent', $promotion?->percent ?? 10)), example: null }">
            @csrf
            @method('PUT')
            <div class="promo-editor-fields">
                <label>Promo name<input name="name" maxlength="100" required value="{{ old('name', $promotion?->name) }}"></label>
                <label>Discount (%)<input x-model.number="percent" value="{{ old('percent', $promotion?->percent ?? 10) }}" name="percent" type="number" min="1" max="100" step="1" required></label>
                <label>Applies to
                    <select name="all_items" x-model.boolean="allItems">
                        <option value="1" @selected(old('all_items', $promotion?->all_items ?? true))>All menu items</option>
                        <option value="0" @selected(!old('all_items', $promotion?->all_items ?? true))>Selected items</option>
                    </select>
                </label>
                <fieldset x-show="!allItems" x-bind:disabled="allItems">
                    <legend>Eligible items</legend>
                    @foreach($products as $product)
                        <label class="promo-checkbox">
                            <input type="checkbox" name="product_ids[]" value="{{ $product->id }}"
                                @checked(in_array((string) $product->id, array_map('strval', old('product_ids', $promotion?->product_ids ?? [])), true))
                                x-on:change="example = $event.target.checked ? {{ (int) $product->price }} : null">
                            <span class="promo-item-label">
                                <strong>{{ $product->name }}</strong>
                                <span class="muted">₱{{ number_format($product->price / 100, 2) }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>
                @foreach(['announces_at' => 'Show announcement from', 'starts_at' => 'Discount starts', 'ends_at' => 'Discount ends'] as $field => $label)
                    <label>{{ $label }}
                        <input type="datetime-local" name="{{ $field }}" required value="{{ old($field, $promotion?->{$field}?->format('Y-m-d\TH:i')) }}">
                    </label>
                @endforeach
                <p class="muted">Schedule timezone {{ config('app.timezone') }}</p>
                <input type="hidden" name="enabled" value="0">
                <label class="promo-checkbox"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $promotion?->enabled ?? false))> Enabled</label>
                <button type="submit" class="btn primary">Save promotion</button>
            </div>
            <section class="promo-price-preview" aria-label="Price preview">
                <h2>Price preview</h2>
                @if($products->isNotEmpty())
                    <label x-show="allItems">Example item
                        <select x-model.number="example">
                            <option value="">Choose an item</option>
                            @foreach($products as $product)
                                <option value="{{ $product->price }}">{{ $product->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <p x-show="!example">Choose an item to preview the discount.</p>
                <div x-show="example">
                    <p>Regular price <strong x-text="'₱' + (example / 100).toFixed(2)"></strong></p>
                    <p>Promo price <strong x-text="'₱' + ((example - Math.round(example * percent / 100)) / 100).toFixed(2)"></strong></p>
                </div>
            </section>
        </form>
    </main>
</div>
@endsection
