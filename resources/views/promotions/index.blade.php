@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('branding/menu-promotions.css').'?v=3' }}">
<div class="app-shell" x-data="{ navOpen: false }">
    <x-sidebar :business="$business" mode="promotions"/>
    <main class="main promo-page">
        <header class="promo-page-heading">
            <h1>Promotions</h1>
            <a class="btn primary" href="{{ route('promotions.create', $business) }}"><x-g-icon name="plus"/>New promotion</a>
        </header>
        @if(session('promo_saved'))<p role="status">{{ session('promo_saved') }}</p>@endif
        <div class="promo-filters">
            <nav aria-label="Filter promotions">
                @foreach($statuses as $key => $label)
                    <a class="chip {{ $status === $key ? 'selected' : '' }}"
                        href="{{ route('promotions.edit', ['business' => $business, 'status' => $key, 'q' => $search]) }}"
                        @if($status === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <form method="get" action="{{ route('promotions.edit', $business) }}">
                <input type="hidden" name="status" value="{{ $status }}">
                <input type="search" name="q" value="{{ $search }}" maxlength="100" aria-label="Search promotions" placeholder="Search promotions">
                <button class="btn">Search</button>
            </form>
        </div>
        <ul class="promo-directory">
            @forelse($promotions as $promotion)
                <li class="card promo-directory-row">
                    <div>
                        <h2>{{ $promotion->name }}</h2>
                        <p>{{ $promotion->percent }}% off · {{ $promotion->all_items ? 'All menu items' : count($promotion->product_ids).' selected items' }}</p>
                    </div>
                    <span class="promo-status promo-status-{{ $promotion->status() }}">{{ ucfirst($promotion->status()) }}</span>
                    @include('promotions.schedule')
                    <a class="btn" href="{{ route('promotions.show', [$business, $promotion]) }}">View details</a>
                </li>
            @empty
                <li class="card promo-empty">{{ $search || $status !== 'all' ? 'No promotions match your filters.' : 'No promotions yet.' }}</li>
            @endforelse
        </ul>
        {{ $promotions->links() }}
    </main>
</div>
@endsection
