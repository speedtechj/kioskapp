@php
    $money = fn ($cents) => '₱'.number_format($cents / 100, 2);
    $shifts = $report['visibleShifts'];
    $shift = $shifts->count() === 1 ? $shifts->first() : null;
    $canManageOrders = \App\Services\Access::allows(auth()->user(), $business->id, 'orders.pay');
    $cashierName = $report['cashiers']->firstWhere('id', $appliedOrderCashier)?->name;
@endphp
<section class="cashier-sales" x-data="{ filtersOpen: false }">
    <div class="cashier-mobile-filters">
        <span>{{ $cashierName ?? 'All cashiers' }} · {{ \Illuminate\Support\Carbon::parse($appliedOrderDate ?: today())->format('M j') }}</span>
        <button class="btn primary" type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="cashier-sales-filters">Filters</button>
    </div>
    <form id="cashier-sales-filters" class="cashier-sales-filters" :class="{ 'is-expanded': filtersOpen }" wire:submit="filterOrders" aria-labelledby="cashier-filters-title">
        <h2 id="cashier-filters-title">Order filters</h2>
        <label>Date<input wire:model="orderDate" type="date" required></label>
        <label>Kiosk<select wire:model.live.number="selectedKiosk">
            <option value="0">All kiosks</option>
            @foreach($kiosks as $kiosk)<option value="{{ $kiosk->id }}">{{ $kiosk->name }}</option>@endforeach
        </select></label>
        <label>Cashier<select wire:model.number="orderCashier">
            <option value="0">All cashiers</option>
            @foreach($report['cashiers'] as $cashier)<option value="{{ $cashier->id }}">{{ $cashier->name }}</option>@endforeach
        </select></label>
        <label>Shift<select wire:model.number="orderShift">
            <option value="0">All shifts</option>
            @foreach($report['shifts'] as $option)
                <option value="{{ $option->id }}">{{ $kiosks->firstWhere('id', $option->kiosk_id)?->name }} · {{ $option->opened_at->format('M j, g:i A') }}</option>
            @endforeach
        </select></label>
        <div class="cashier-filter-actions">
            <button type="button" class="btn" wire:click="showTodaysOrders">Today</button>
            <button type="submit" class="btn primary">Apply filters</button>
        </div>
    </form>
    @error('orderDate')<p class="error" role="alert">{{ $message }}</p>@enderror

    <div class="cashier-sales-summary">
        <article class="card cashier-sales-total">
            <h2>Collected sales</h2><strong>{{ $money($report['collected']) }}</strong>
            <p>Payments recorded by the collecting cashier, before refunds.</p>
        </article>
        <article class="card cashier-shift-summary">
            @if($shift)
                <div class="cashier-shift-stat"><h2>Shift opened</h2><strong>{{ $shift->opened_at->format('g:i A') }}</strong></div>
                <div class="cashier-shift-stat"><h2>Shift closed</h2><strong>{{ $shift->closed_at?->format('g:i A') ?? 'Still open' }}</strong></div>
            @else
                <div><h2>Shift activity</h2><strong>{{ $shifts->count() }} {{ Str::plural('shift', $shifts->count()) }}</strong></div>
            @endif
        </article>
    </div>

    <div class="cashier-sales-table">
        <table aria-label="Orders and cashier sales">
            <thead><tr><th scope="col">Order</th><th scope="col">Taken by</th><th scope="col">Collected by</th><th scope="col">Adjustment</th><th scope="col">Approved by</th><th scope="col">Collected</th><th scope="col">Action</th></tr></thead>
            <tbody>
            @forelse($report['orders'] as $order)
                @php
                    $payments = $report['displayPayments']->get($order->id, collect());
                    $collectors = $payments->pluck('recorded_by')->unique()->map(fn ($id) => $report['names'][$id] ?? 'Not recorded')->implode(', ');
                    $complimentaryItems = $order->items->where('complimentary', true)->pluck('name')->unique();
                    $hasComplimentary = $order->complimentary_amount || $complimentaryItems->isNotEmpty();
                    $approvals = collect($order->adjustment_approvals ?? []);
                    $approvers = $approvals->pluck('manager_id')->filter()->unique()->map(fn ($id) => $report['names'][$id] ?? 'Not recorded')->implode(', ');
                @endphp
                <tr wire:key="cashier-sales-order-{{ $order->id }}">
                    <td><a href="{{ route('tracking', $order->slug) }}"><strong>#{{ str_pad($order->id, 4, '0', STR_PAD_LEFT) }}</strong></a>
                        <small>{{ $order->kiosk->name }} · {{ $order->table?->name ?? 'Takeout' }}</small>
                        <small>{{ Str::headline($order->status) }} · {{ Str::headline($order->payment_status) }}</small></td>
                    <td data-label="Taken by">{{ $report['names'][$order->user_id] ?? ($order->source === 'qr' ? 'Customer QR' : 'Not recorded') }}</td>
                    <td data-label="Collected by">{{ $collectors ?: '—' }}</td>
                    <td data-label="Adjustment">
                        <div class="cashier-adjustments">
                            @if($order->discount_amount)
                                <div><strong>Discount</strong> −{{ $money($order->discount_amount) }}</div>
                            @endif
                            @if($hasComplimentary)
                                <div>
                                    <strong>Complimentary</strong>
                                    @foreach($complimentaryItems as $item)
                                        <span class="cashier-complimentary-item">{{ $item }}</span>
                                    @endforeach
                                    <strong class="cashier-complimentary-item">−{{ $money($order->complimentary_amount) }}</strong>
                                </div>
                            @elseif(! $order->discount_amount)
                                —
                            @endif
                        </div>
                    </td>
                    <td data-label="Approved by">
                        @if($approvers)
                            <details wire:key="order-approval-{{ $order->id }}" wire:ignore.self>
                                <summary>{{ $approvers }}</summary>
                                <small>Approval history</small>
                                @foreach($approvals as $approval)
                                    <small>{{ $report['names'][$approval['manager_id'] ?? 0] ?? 'Not recorded' }} · {{ $approval['action'] ?? 'Adjustment' }}@if(!empty($approval['approved_at'])) · {{ \Illuminate\Support\Carbon::parse($approval['approved_at'])->setTimezone(config('app.timezone'))->format('M j, g:i A') }}@endif</small>
                                @endforeach
                            </details>
                        @elseif($order->discount_amount || $hasComplimentary)
                            Not recorded
                        @else
                            —
                        @endif
                    </td>
                    <td data-label="Collected"><strong>{{ $money($payments->sum('amount')) }}</strong></td>
                    <td>
                        @if($canManageOrders)
                            <a class="btn cashier-manage-order" href="{{ route('payments', [$business, $order->kiosk, $order]) }}">
                                <span class="cashier-desktop-manage">Manage</span><span class="cashier-mobile-manage">Manage order</span>
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">No orders match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($shifts->isNotEmpty())
        <section class="card cashier-shift-activity">
            <h2>Shift activity</h2>
            @foreach($shifts as $entry)
                <div class="cashier-shift-entry">
                    <strong>{{ $kiosks->firstWhere('id', $entry->kiosk_id)?->name }}</strong>
                    <span>Opened by {{ $report['names'][$entry->opened_by] ?? 'Not recorded' }} · {{ $entry->opened_at->format('M j, g:i A') }}</span>
                    <span>{{ $entry->closed_at ? 'Closed by '.($report['names'][$entry->closed_by] ?? 'Not recorded').' · '.$entry->closed_at->format('M j, g:i A') : 'Still open' }}</span>
                </div>
            @endforeach
        </section>
    @endif
</section>
