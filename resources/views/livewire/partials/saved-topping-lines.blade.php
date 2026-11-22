@foreach($order->topping_lines ?? [] as $topping)
    <div class="{{ ($kitchen ?? false) ? 'ticket-item' : 'total-row' }}">
        <span>{{ $topping['quantity'] }} × {{ $topping['name'] }} (extra) @if($topping['complimentary'] ?? false) · Complimentary @endif</span>
        @unless($kitchen ?? false)<strong>₱{{ number_format($topping['total'] / 100, 2) }}</strong>@endunless
    </div>
@endforeach
