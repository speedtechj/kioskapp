@if($toppingSubtotal > 0)
    <div class="topping-subtotal-breakdown">
        @foreach($toppings as $topping)
            @php($quantity = $toppingQuantities[$topping->id] ?? 0)
            @if($topping->active && $quantity > 0)
                <div class="total-row">
                    <span>Includes extra {{ $topping->name }} ({{ $quantity }} {{ \Illuminate\Support\Str::plural('piece', $quantity) }})</span>
                    <span>
                        @if(in_array($topping->id, $freeToppingIds ?? [], true))
                            <del>₱{{ number_format($topping->price * $quantity / 100, 2) }}</del>
                            <small>Complimentary</small>
                        @else
                            ₱{{ number_format($topping->price * $quantity / 100, 2) }}
                        @endif
                    </span>
                </div>
            @endif
        @endforeach
    </div>
@endif
