@props(['received', 'due' => null, 'amount' => null])
@assets
<link rel="stylesheet" href="{{ asset('branding/cash-checkout.css') }}">
@endassets
<div {{ $attributes->class("cash-change") }} x-data="{
    cents(value) {
        const parts = /^([0-9]{1,7})(?:[.]([0-9]{1,2}))?$/.exec(String(value ?? ''))
        return parts ? Number(parts[1]) * 100 + Number((parts[2] ?? '').padEnd(2, '0')) : null
    },
    get due() { return {{ $amount === null ? (int) $due : 'this.cents($wire.get('.Js::from($amount).'))' }} },
    get received() { return this.cents($wire.get(@js($received))) },
    get difference() { return this.due > 0 && this.received !== null ? this.received - this.due : null },
    fill(value) { $wire.set(@js($received), value, false) },
    pesos(cents) { return '₱' + (cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
}">
    @if($amount === null)
        <div class="cash-change-total"><strong>Order total</strong><strong>₱{{ number_format($due / 100, 2) }}</strong></div>
    @endif
    <label>Cash received
        <input type="text" inputmode="decimal" autocomplete="off" maxlength="10" placeholder="0.00"
            :value="$wire.get(@js($received)) ?? ''" @input="fill($event.target.value)">
    </label>
    <div class="cash-change-quick">
        <button type="button" class="btn" :disabled="!due" @click="fill((due / 100).toFixed(2))">Exact</button>
        @foreach([200, 500, 1000] as $bill)
            <button type="button" class="btn" x-show="due > 0 && due <= {{ $bill * 100 }}" @click="fill('{{ $bill }}.00')">₱{{ number_format($bill) }}</button>
        @endforeach
    </div>
    <div class="cash-change-result" :class="{'cash-change-short': difference < 0}" aria-live="polite">
        <span x-text="difference !== null && difference < 0 ? 'Still due' : 'Change to give'">Change to give</span>
        <strong x-text="difference === null ? '—' : pesos(Math.abs(difference))">—</strong>
    </div>
</div>
