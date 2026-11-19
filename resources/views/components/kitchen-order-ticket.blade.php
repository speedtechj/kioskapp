@props(['order', 'canAdvance'])
@php
    $takeout = $order->dining_table_id === null;
    $paymentPending = $takeout && $order->status === 'ready' && $order->payment_status !== 'paid';
    $status = ['received' => 'New order', 'preparing' => 'Preparing', 'ready' => 'Ready'][$order->status];
    $action = ['received' => 'Start preparing', 'preparing' => 'Mark ready', 'ready' => $takeout ? 'Mark picked up' : 'Mark served'][$order->status];
@endphp
<article class="kitchen-order-ticket" wire:key="ticket-{{ $order->id }}">
    <header>
        <div><h3>#{{ str_pad($order->id, 4, '0', STR_PAD_LEFT) }}</h3>
            <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('M j · g:i A') }}</time>
            @if($takeout && $order->customer_name)<span> · {{ $order->customer_name }}</span>@endif
        </div>
        <div class="kitchen-ticket-status">
            <span class="kitchen-status status-{{ $order->status }}">{{ $status }}</span>
            @if($paymentPending)<span class="kitchen-status status-preparing">Payment pending</span>@endif
        </div>
    </header>
    <ul>
        @foreach($order->items as $item)
            <li><strong>{{ $item->quantity }} ×</strong> {{ $item->name }}</li>
        @endforeach
    </ul>
    @if($order->notes)<p class="kitchen-order-note"><strong>Note</strong> {{ $order->notes }}</p>@endif
    @if($paymentPending)
        <p class="kitchen-pickup-warning">Collect payment before pickup.</p>
    @elseif($canAdvance)
        <button type="button" class="btn primary kitchen-ticket-action {{ $order->status === 'ready' ? 'kitchen-handoff' : '' }}"
            wire:click="advance({{ $order->id }}, '{{ $order->status }}')"
            wire:loading.attr="disabled" wire:target="advance">{{ $action }}</button>
    @endif
</article>
