<link rel="stylesheet" href="{{ asset('branding/sales-summary.css') }}">
<nav class="sales-tabs" aria-label="Reports">
    <a href="{{ route('workspace', ['business' => $business, 'tab' => 'reports']) }}" @if(! $salesActive) aria-current="page" @endif>Overview</a>
    <a href="{{ route('reports.sales', ['business' => $business, 'kiosk' => ($selectedKiosk ?? 0) ?: null]) }}" @if($salesActive) aria-current="page" @endif>Sales Summary</a>
</nav>
