@if($toppingSubtotal > 0)
    <div class="topping-subtotal-breakdown">
        @foreach($toppings as $topping)
            @php($quantity = $toppingQuantities[$topping->id] ?? 0)
            @if($topping->active && $quantity > 0)
                <div class="total-row">
                    <span>Includes extra {{ $topping->name }} ({{ $quantity }} {{ \Illuminate\Support\Str::plural('piece', $quantity) }})</span>
                    <span>₱{{ number_format($topping->price * $quantity / 100, 2) }}</span>
                </div>
            @endif
        @endforeach
    </div>
@endif
