@extends('layouts.app', ['title' => 'Sales Summary'])

@section('content')
<div class="app-shell" x-data="{ navOpen: false }">
    <x-sidebar :business="$business" mode="reports"/>
    <main class="main sales-summary">
        <header class="topbar">
            <div class="heading-row">
                <button type="button" class="mobile-only icon-btn" @click="navOpen = true" aria-label="Open navigation"><x-g-icon name="bars-3"/></button>
                <h1>Reports</h1>
            </div>
        </header>
        @include('reports.tabs', ['salesActive' => true])

        @php
            $money = fn ($value) => ($value < 0 ? '−' : '').'₱'.number_format(abs($value) / 100, 2);
            $metrics = ['gross' => 'Gross sales', 'discounts' => 'Discounts', 'refunds' => 'Refunds', 'net' => 'Net sales', 'paid_orders' => 'Paid orders'];
            $csv = ['business' => $business, 'period' => $period, 'kiosk' => $kiosk, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'format' => 'csv'];
            $max = max(1, ...array_map(fn ($day) => abs($day['net']), array_values($report['days'])));
            $hasNegativeDays = min(array_column($report['days'], 'net')) < 0;
        @endphp

        <div class="sales-controls" x-data="{ expanded: {{ $errors->any() ? 'true' : 'false' }}, period: @js(old('period', $period)) }">
            <button type="button" class="btn sales-filter-toggle" aria-controls="sales-filters" :aria-expanded="expanded" @click="expanded = ! expanded">
                Filters <span>{{ $periods[$period] }} · {{ $kiosks[$kiosk] ?? 'All kiosks' }}</span>
            </button>
            <form id="sales-filters" class="sales-filters" :class="{ 'is-expanded': expanded }" method="get" action="{{ route('reports.sales', $business) }}">
                <select name="period" aria-label="Date range" x-model="period">
                    @foreach($periods as $key => $label)
                        <option value="{{ $key }}" @selected(old('period', $period) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="kiosk" aria-label="Kiosk">
                    <option value="">All kiosks</option>
                    @foreach($kiosks as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('kiosk', $kiosk) === $id)>{{ $name }}</option>
                    @endforeach
                </select>
                <div class="sales-custom" x-show="period === 'custom'" @if(old('period', $period) !== 'custom') style="display:none" @endif>
                    <label>From <input type="date" name="start" value="{{ old('start', $start->toDateString()) }}" :required="period === 'custom'"></label>
                    <label>To <input type="date" name="end" value="{{ old('end', $end->toDateString()) }}" :required="period === 'custom'"></label>
                </div>
                <button type="submit" class="btn primary">Apply filters</button>
            </form>
            <a class="btn sales-export" href="{{ route('reports.sales', $csv) }}">Export CSV</a>
        </div>
        @if($errors->any())
            <p class="sales-error" role="alert">{{ $errors->first() }}</p>
        @endif

        <section class="sales-metrics" aria-label="Sales totals">
            @foreach($metrics as $key => $label)
                <article class="card sales-metric sales-metric-{{ $key }}">
                    <span>{{ $label }}</span>
                    <strong>{{ $key === 'paid_orders' ? number_format($report['totals'][$key]) : $money($report['totals'][$key]) }}</strong>
                </article>
            @endforeach
        </section>

        <div class="sales-breakdowns">
            <section class="card sales-chart">
                <h2>Daily net sales</h2>
                <p class="muted">{{ $start->format('M j, Y') }} – {{ $end->format('M j, Y') }}</p>
                <div class="sales-chart-scroll" tabindex="0" role="region" aria-label="Daily net sales chart">
                    <div class="sales-bars" style="--days: {{ count($report['days']) }}">
                        @foreach($report['days'] as $day => $row)
                            <div class="sales-bar">
                                <span>{{ $money($row['net']) }}</span>
                                <div class="sales-track{{ $hasNegativeDays ? ' has-negative' : '' }}" aria-hidden="true"><i class="{{ $row['net'] < 0 ? 'is-negative' : '' }}" style="--height: {{ abs($row['net']) / $max * 100 }}%"></i></div>
                                <time datetime="{{ $day }}">{{ \Illuminate\Support\Carbon::parse($day)->format('M j') }}</time>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
            <section class="card sales-payments">
                <h2>Payments collected</h2>
                <strong class="sales-payment-total">{{ $money($report['methods']->sum()) }}</strong>
                <dl class="sales-list">
                    @forelse($report['methods'] as $method => $amount)
                        <div><dt>{{ \App\Http\Controllers\SalesSummaryController::paymentLabel($method) }}</dt><dd>{{ $money($amount) }}</dd></div>
                    @empty
                        <div><dt>No payments recorded for this period.</dt></div>
                    @endforelse
                </dl>
            </section>
        </div>
        <section class="card sales-kiosks">
            <h2>Sales by kiosk</h2>
            <dl class="sales-list">
                @forelse($report['kiosks'] as $id => $row)
                    <div><dt>{{ $kiosks[$id] }}</dt><dd>{{ $money($row['net']) }}</dd></div>
                @empty
                    <div><dt>No kiosks available.</dt></div>
                @endforelse
            </dl>
        </section>
        <p class="muted sales-note">Sales use the order date. Discounts include complimentary items. Refunds and recorded payments use their confirmation dates. Older paid orders without payment records use the order date for collections.</p>
    </main>
</div>
@endsection
