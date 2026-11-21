@if($toppings->isNotEmpty())
    @assets
        <link rel="stylesheet" href="{{ asset('branding/order-toppings.css') }}">
    @endassets
    <details class="order-toppings" wire:key="order-toppings" wire:ignore.self>
        <summary>+ Add toppings (optional)</summary>
        @foreach($toppings as $topping)
            @php($quantity = $toppingQuantities[$topping->id] ?? 0)
            <div class="order-topping" wire:key="order-topping-{{ $topping->id }}">
                <div>
                    <strong>{{ $topping->name }}</strong>
                    <small>{{ $topping->active ? '₱'.number_format($topping->price / 100, 2).' per piece' : 'Unavailable' }}</small>
                </div>
                <div class="order-topping-quantity">
                    <button type="button" class="btn" aria-label="Remove one {{ $topping->name }}" wire:click="changeTopping({{ $topping->id }}, false)" @disabled(!$quantity || !($canEdit ?? true))>−</button>
                    <span aria-live="polite">{{ $quantity }}</span>
                    <button type="button" class="btn" aria-label="Add one {{ $topping->name }}" wire:click="changeTopping({{ $topping->id }}, true)" @disabled(!$topping->active || !$canIncrease || !($canEdit ?? true) || $quantity >= 99)>+</button>
                </div>
            </div>
        @endforeach
    </details>
@endif
