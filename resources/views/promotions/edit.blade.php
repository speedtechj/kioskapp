@extends('layouts.app')

@section('content')
@php
    $editorTitle = $promotion ? 'Edit promotion' : 'New promotion';
    $saveLabel = $promotion ? 'Save changes' : 'Save promotion';
    $backUrl = $promotion ? route('promotions.show', [$business, $promotion]) : route('promotions.edit', $business);
    $allItems = (bool) old('all_items', $promotion?->all_items ?? false);
    $productSelection = old('selection_submitted') ? old('product_ids', []) : ($promotion?->product_ids ?? []);
    $productSelection = is_array($productSelection) ? array_values(array_map('intval', array_filter($productSelection, 'is_scalar'))) : [];
@endphp
<link rel="stylesheet" href="{{ asset('branding/menu-promotions.css').'?v=4' }}">
<div class="app-shell" x-data="{ navOpen: false }">
    <x-sidebar :business="$business" mode="promotions"/>
    <main class="main promo-page promo-editor-page">
        <nav class="promo-editor-breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('promotions.edit', $business) }}">Promotions</a>
            @if($promotion)
                <span aria-hidden="true">/</span><a href="{{ $backUrl }}">{{ $promotion->name }}</a>
            @endif
            <span aria-hidden="true">/</span><span aria-current="page">{{ $promotion ? 'Edit' : 'New promotion' }}</span>
        </nav>
        <header class="promo-page-heading promo-editor-heading"><h1>{{ $editorTitle }}</h1></header>
        @if(session('promo_saved'))<p role="status">{{ session('promo_saved') }}</p>@endif
        @if($errors->any())
            <ul role="alert" class="error">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif

        <form class="promo-editor promo-editor-redesign" action="{{ $promotion ? route('promotions.update', [$business, $promotion]) : route('promotions.store', $business) }}" method="post"
            x-data="{
                allItems: @js($allItems),
                percent: @js((int) old('percent', $promotion?->percent ?? 10)),
                products: @js($products->values()),
                selected: @js($productSelection),
                pending: [],
                search: '',
                money(cents) { return '₱' + (cents / 100).toFixed(2) },
                get chosen() { return this.products.filter(item => this.selected.includes(item.id)) },
                get previewItems() { return (this.allItems ? this.products : this.chosen).slice(0, 3) },
                get available() { return this.products.filter(item => !this.selected.includes(item.id) && item.name.toLowerCase().includes(this.search.trim().toLowerCase())) },
                promoPrice(cents) { return cents - Math.round(cents * Math.min(100, Math.max(0, this.percent || 0)) / 100) }
            }">
            @csrf
            @if($promotion) @method('PUT') @endif
            <input type="hidden" name="selection_submitted" value="1">
            <div class="promo-editor-fields">
                <section class="card promo-editor-card" aria-labelledby="promo-offer-heading">
                    <h2 id="promo-offer-heading">Offer</h2>
                    <label for="promo-name">Promotion name
                        <input id="promo-name" name="name" maxlength="100" required placeholder="e.g. Weekend bowl promo" value="{{ old('name', $promotion?->name) }}">
                    </label>
                    <label for="promo-percent">Discount percentage
                        <span class="promo-percent-field">
                            <input id="promo-percent" x-model.number="percent" value="{{ old('percent', $promotion?->percent ?? 10) }}" name="percent" type="number" min="1" max="100" step="1" required>
                            <span aria-hidden="true">%</span>
                        </span>
                    </label>
                    <fieldset class="promo-scope">
                        <legend>Applies to</legend>
                        <div class="promo-scope-options">
                            <label><input type="radio" name="all_items" value="0" x-model.boolean="allItems" @checked(!$allItems)><span>Selected items</span></label>
                            <label><input type="radio" name="all_items" value="1" x-model.boolean="allItems" @checked($allItems)><span>All menu items</span></label>
                        </div>
                    </fieldset>
                </section>
                <fieldset class="card promo-editor-card promo-editor-items" x-show="!allItems" x-bind:disabled="allItems" aria-labelledby="promo-items-heading">
                    <div class="promo-editor-section-heading">
                        <h2 id="promo-items-heading">Selected items <span class="promo-count" x-text="chosen.length + ' selected'"></span></h2>
                        <button type="button" class="btn" aria-haspopup="dialog" x-on:click="pending = []; search = ''; $refs.itemPicker.showModal()"><x-g-icon name="plus"/>Add items</button>
                    </div>
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
                <section class="card promo-editor-card" aria-labelledby="promo-schedule-heading">
                    <h2 id="promo-schedule-heading">Schedule</h2>
                    @foreach(['announces_at' => 'Announcement starts', 'starts_at' => 'Discount starts', 'ends_at' => 'Discount ends'] as $field => $label)
                        <label>{{ $label }}
                            <input type="datetime-local" name="{{ $field }}" required value="{{ old($field, $promotion?->{$field}?->format('Y-m-d\TH:i')) }}">
                        </label>
                    @endforeach
                    <p class="muted">Timezone {{ config('app.timezone') === 'Asia/Manila' ? 'Philippine time (PHT)' : config('app.timezone') }}</p>
                </section>
            </div>
            <aside class="promo-editor-aside">
                <section class="card promo-editor-card" aria-labelledby="promo-preview-heading">
                    <h2 id="promo-preview-heading">Customer preview</h2>
                    <div class="promo-editor-preview-banner">
                        <x-g-icon name="tag"/>
                        <strong x-text="'Coming soon · ' + (percent || 0) + '% off ' + (allItems ? 'all menu items' : 'selected items')"></strong>
                    </div>
                    <p class="muted" x-show="previewItems.length === 0">Choose items to preview their prices.</p>
                    <ul class="promo-selection-list promo-preview-items">
                        <template x-for="item in previewItems" :key="item.id">
                            <li>
                                <strong x-text="item.name"></strong>
                                <span class="promo-price-pair"><del x-text="money(item.price)"></del><strong x-text="money(promoPrice(item.price))"></strong></span>
                            </li>
                        </template>
                    </ul>
                    <p class="muted" x-show="(allItems ? products.length : chosen.length) > previewItems.length" x-text="'Showing ' + previewItems.length + ' of ' + (allItems ? products.length : chosen.length) + ' items'"></p>
                </section>
                <input type="hidden" name="enabled" value="0">
                <label class="card promo-editor-enable">
                    <span>Enabled</span>
                    <span class="promo-editor-switch">
                        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $promotion?->enabled ?? false))>
                        <span aria-hidden="true"></span>
                    </span>
                </label>
            </aside>
            <div class="promo-editor-save">
                <a class="btn" href="{{ $backUrl }}">Cancel</a>
                <button type="submit" class="btn primary">{{ $saveLabel }}</button>
            </div>
        </form>
    </main>
</div>
@endsection
