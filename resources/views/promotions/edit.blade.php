@extends('layouts.app')

@section('content')
@php
    $productSelection = old('selection_submitted') ? old('product_ids', []) : ($promotion?->product_ids ?? []);
    $productSelection = is_array($productSelection) ? array_values(array_map('intval', array_filter($productSelection, 'is_scalar'))) : [];
@endphp
<link rel="stylesheet" href="{{ asset('branding/menu-promotions.css').'?v=3' }}">
<div class="app-shell" x-data="{ navOpen: false }">
    <x-sidebar :business="$business" mode="promotions"/>
    <main class="main">
        <a class="promo-back" href="{{ $promotion ? route('promotions.show', [$business, $promotion]) : route('promotions.edit', $business) }}">← {{ $promotion ? 'Promotion details' : 'Promotions' }}</a>
        <header class="topbar"><h1>{{ $promotion ? 'Edit promotion' : 'New promotion' }}</h1></header>
        @if(session('promo_saved'))<p role="status">{{ session('promo_saved') }}</p>@endif
        @if($errors->any())
            <ul role="alert" class="error">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif

        <form class="card promo-editor" action="{{ $promotion ? route('promotions.update', [$business, $promotion]) : route('promotions.store', $business) }}" method="post"
            x-data="{
                allItems: @js((bool) old('all_items', $promotion?->all_items ?? true)),
                percent: @js((int) old('percent', $promotion?->percent ?? 10)),
                example: null,
                products: @js($products->values()),
                selected: @js($productSelection),
                pending: [],
                search: '',
                money(cents) { return '₱' + (cents / 100).toFixed(2) },
                get chosen() { return this.products.filter(item => this.selected.includes(item.id)) },
                get available() { return this.products.filter(item => !this.selected.includes(item.id) && item.name.toLowerCase().includes(this.search.trim().toLowerCase())) },
                promoPrice(cents) { return cents - Math.round(cents * Math.min(100, Math.max(0, this.percent || 0)) / 100) }
            }">
            @csrf
            @if($promotion) @method('PUT') @endif
            <input type="hidden" name="selection_submitted" value="1">
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
                    <legend>Selected items <span class="promo-count" x-text="chosen.length + ' selected'"></span></legend>
                    <button type="button" class="btn" x-on:click="pending = []; search = ''; $refs.itemPicker.showModal()"><x-g-icon name="plus"/>Add items</button>
                    <p class="muted" x-show="chosen.length === 0">Add at least one menu item.</p>
                    <ul class="promo-selection-list">
                        <template x-for="item in chosen" :key="item.id">
                            <li>
                                <input type="hidden" name="product_ids[]" :value="item.id">
                                <div>
                                    <strong x-text="item.name"></strong>
                                    <div class="promo-price-pair"><del x-text="money(item.price)"></del> <strong x-text="money(promoPrice(item.price))"></strong></div>
                                </div>
                                <button class="btn promo-danger" type="button" :aria-label="'Remove ' + item.name"
                                    x-on:click="selected = selected.filter(id => id !== item.id)">Remove</button>
                            </li>
                        </template>
                    </ul>
                    <p class="muted" x-show="chosen.length">Removing an item only removes it from this promotion.</p>
                    <dialog class="menu-promo-dialog promo-item-picker" x-ref="itemPicker" aria-label="Add menu items">
                        <div class="promo-page-heading"><h2>Add items</h2>
                            <button type="button" class="icon-btn" aria-label="Close item picker" x-on:click="$refs.itemPicker.close()">×</button>
                        </div>
                        <input type="search" x-model="search" placeholder="Search menu items" aria-label="Search menu items" autofocus>
                        <div class="promo-selection-list">
                            <template x-for="item in available" :key="item.id">
                                <label class="promo-checkbox">
                                    <input type="checkbox" :checked="pending.includes(item.id)"
                                        x-on:change="pending = $event.target.checked ? [...pending, item.id] : pending.filter(id => id !== item.id)">
                                    <span x-text="item.name"></span>
                                </label>
                            </template>
                            <p class="muted" x-show="available.length === 0">No items to add.</p>
                        </div>
                        <button type="button" class="btn primary wide" :disabled="!pending.length"
                            x-on:click="selected = [...selected, ...pending]; $refs.itemPicker.close()"
                            x-text="pending.length ? 'Add ' + pending.length + (pending.length === 1 ? ' item' : ' items') : 'Add items'"></button>
                    </dialog>
                </fieldset>
                @foreach(['announces_at' => 'Show announcement from', 'starts_at' => 'Discount starts', 'ends_at' => 'Discount ends'] as $field => $label)
                    <label>{{ $label }}
                        <input type="datetime-local" name="{{ $field }}" required value="{{ old($field, $promotion?->{$field}?->format('Y-m-d\TH:i')) }}">
                    </label>
                @endforeach
                <p class="muted">Schedule timezone {{ config('app.timezone') }}</p>
                <input type="hidden" name="enabled" value="0">
                <label class="promo-checkbox"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $promotion?->enabled ?? false))> Enabled</label>
                <button type="submit" class="btn primary">{{ $promotion ? 'Save changes' : 'Save promotion' }}</button>
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
                <p x-show="allItems && example === null">Choose an item to preview the discount.</p>
                <div x-show="allItems && example !== null && example !== ''">
                    <p>Regular price <strong x-text="'₱' + (example / 100).toFixed(2)"></strong></p>
                    <p>Promo price <strong x-text="'₱' + (promoPrice(example) / 100).toFixed(2)"></strong></p>
                </div>
                <div x-show="!allItems">
                    <p x-show="chosen.length === 0">Choose items to preview their prices.</p>
                    <ul class="promo-selection-list">
                        <template x-for="item in chosen" :key="item.id">
                            <li><div><strong x-text="item.name"></strong><p class="muted" x-text="'Save ' + money(item.price - promoPrice(item.price))"></p></div>
                                <strong class="promo-status promo-status-active" x-text="money(promoPrice(item.price))"></strong></li>
                        </template>
                    </ul>
                </div>
            </section>
        </form>
    </main>
</div>
@endsection
