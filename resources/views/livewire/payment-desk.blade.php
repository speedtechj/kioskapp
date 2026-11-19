@assets
<link rel="stylesheet" href="{{ asset('branding/staff-pin.css') }}">
<link rel="stylesheet" href="{{ asset('branding/register-shift.css') }}">
@endassets
<div class="app-shell" x-data="{navOpen:false}"><x-sidebar :business="$business" :kiosk="$kiosk" mode="payments"/><main class="main" wire:poll.10s><header class="topbar"><div class="heading-row"><button class="mobile-only icon-btn" @click="navOpen=true" aria-label="Open navigation"><x-g-icon name="bars-3"/></button><div><span class="eyebrow">ONE ORDER. MORE WAYS TO PAY.</span><h1>Split payment<span class="brand-dot">.</span></h1><p class="muted">Order #{{ str_pad($order->id,4,'0',STR_PAD_LEFT) }} · {{ $order->table?->name ?? 'Takeout' }} · {{ $kiosk->name }}</p></div></div><a class="btn" href="{{ route('pos',[$business,$kiosk]) }}">Back to POS</a></header>
<x-register-shift-controls :shift="$registerShift" :drawer-totals="$drawerTotals" :cash-counts="$cashCounts"/>
<x-manager-adjustment :proposal="$pendingAdjustment" :business="$business" :kiosk="$kiosk"/>
@error('adjustment')<p class="error" role="alert">{{ $message }}</p>@enderror
@if(session('payment_success'))<p class="success-banner gd-success" role="status"><x-g-icon name="check-circle"/><span>{{ session('payment_success') }}</span></p>@endif
@if($order->status==='cancelled')
<x-cancelled-order :order="$order" :staff="true"/>
@else
@if(in_array($order->status,['received','preparing','ready'],true))
<button class="btn" wire:click="cancel" wire:loading.attr="disabled" data-gather-title="Cancel this order?" data-context="Order #{{ str_pad($order->id,4,'0',STR_PAD_LEFT) }}" data-message="Stop preparation and mark any collected payments for refund." data-back="Keep order" data-commit="Cancel order">Cancel order</button>
@endif
@error('cancel')<p class="error">{{ $message }}</p>@enderror
<div class="payment-desk-grid"><section class="card"><div class="section-line"><h2>Record collected payments</h2><span class="badge {{ $order->payment_status }}">{{ ucfirst($order->payment_status) }}</span></div><p class="muted">Collect cash or verify GCash/card on your external terminal first. This screen records payments; it does not process them.</p>
@if($canApplyDiscount)
<div class="desk-payment-row">
    <form wire:submit="requestDeskDiscount">
        <h3>Apply discount</h3>
        <div class="form-grid">
            <label>Discount type
                <select wire:model="discountType"><option value="percent">Percentage (%)</option><option value="fixed">Fixed amount (₱)</option></select>
            </label>
            <label>Discount amount<input wire:model="discountValue" type="text" inputmode="decimal" maxlength="10" placeholder="0.00" required></label>
        </div>
        <button type="submit" class="btn" wire:loading.attr="disabled">Review discount</button>
    </form>
</div>
@endif
@if($canAdjustOrder && $order->discount_amount)
    <div class="desk-payment-row"><strong>Discount applied</strong>
        @if($order->adjustment_approvals)<span class="badge paid">Manager approved</span>@endif
        <button class="btn" type="button" wire:click="requestDeskDiscount(true)">Remove discount</button>
        <p class="muted">Remove the discount to make an item free.</p>
    </div>
@elseif($canAdjustOrder && !$canApplyDiscount)
    <div class="desk-payment-row"><strong>Complimentary items applied</strong>
        <button class="btn" type="button" disabled>Apply discount</button>
        <p class="muted">Undo all free items to apply a discount.</p>
    </div>
@endif
@error('discountValue')<p class="error" role="alert">{{ $message }}</p>@enderror
@error('discountType')<p class="error" role="alert">{{ $message }}</p>@enderror
@if($canAdjustOrder)
<details class="desk-payment-row" wire:key="desk-complimentary" wire:ignore.self>
    <summary>Complimentary items</summary>
    @foreach($order->items as $item)
        <div class="total-row" wire:key="desk-complimentary-{{ $item->id }}">
            <span>{{ $item->quantity }} × {{ $item->name }}</span>
            <button type="button" class="btn" wire:click="requestDeskComplimentary({{ $item->id }}, {{ $item->complimentary ? 'false' : 'true' }})" wire:loading.attr="disabled" @disabled($order->discount_amount && ! $item->complimentary)>
                {{ $item->complimentary ? 'Undo free item' : 'Make item free' }}
            </button>
        </div>
    @endforeach
</details>
@endif
@error('complimentary')<p class="error" role="alert">{{ $message }}</p>@enderror
@if(!$registerShift && $order->remainingAmount() > 0)<p class="register-payment-locked">Open a shift to collect this payment.</p>@endif
@if($registerShift && $order->remainingAmount()>0)<form wire:submit="record(true)" data-gather-title="Record collected payments?" data-context="Order #{{ str_pad($order->id,4,'0',STR_PAD_LEFT) }}" data-message="Confirm that you collected or externally verified every amount entered." data-back="Go back" data-commit="Record payments">
@foreach($payments as $i=>$payment)<div class="desk-payment-row" wire:key="payment-row-{{ $i }}"><span class="field-caption">PAYMENT {{ $i+1 }}</span><div class="form-grid"><label>Method<select wire:model.live="payments.{{ $i }}.method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="card">Card</option></select></label><label>Amount (PHP)<input type="text" inputmode="decimal" wire:model="payments.{{ $i }}.amount" placeholder="0.00" required></label></div>
@if(($payment['method'] ?? null) === 'cash')
<x-cash-change received="payments.{{ $i }}.cash_received" amount="payments.{{ $i }}.amount" />
@endif
<label>Reference <span class="muted">(optional, staff only)</span><input wire:model="payments.{{ $i }}.reference" maxlength="80" placeholder="External payment reference"></label>@if(count($payments)>1)<button class="text-link" type="button" wire:click="removePayment({{ $i }})">Remove payment</button>@endif</div>@endforeach
<button type="button" class="btn" wire:click="addPayment" @disabled(count($payments)>=8)><x-g-icon name="plus"/>Add another payment method</button>
@foreach($errors->all() as $error)<p class="error" role="alert">{{ $error }}</p>@endforeach
<p class="payment-note">You may record a partial payment now, then collect the remaining balance later. Overpayments are rejected.</p><button class="btn primary wide" wire:loading.attr="disabled" wire:target="record">Confirm collected payments <x-g-icon name="check"/></button></form>@else<div class="empty"><span class="success-icon" style="margin-inline:auto"><x-g-icon name="check"/></span><h2>Fully paid.</h2><p>There is no remaining balance to collect.</p></div>@endif</section>
<aside><section class="card"><h2>Order summary</h2><div style="margin-top:22px">@foreach($order->items as $item)<div class="total-row"><span>{{ $item->quantity }} × {{ $item->name }} @if($item->complimentary)<strong>· Complimentary</strong>@endif</span><strong>₱{{ number_format($item->total/100,2) }}</strong></div>@endforeach<x-order-adjustment-totals :order="$order" /><div class="total-row"><span>Service charge</span><span>₱{{ number_format($order->service_charge/100,2) }}</span></div><div class="total-row grand-total"><strong>Order total</strong><strong>₱{{ number_format($order->total/100,2) }}</strong></div><div class="total-row"><span>Paid</span><strong>₱{{ number_format($order->paidAmount()/100,2) }}</strong></div><div class="total-row"><span>Remaining</span><strong>₱{{ number_format($order->remainingAmount()/100,2) }}</strong></div></div><a class="text-link" href="{{ route('tracking',['token'=>$order->slug,'view'=>'payment']) }}" target="_blank">Customer payment view ↗</a></section>
<section class="card" style="margin-top:20px"><h2>Confirmed payments</h2>@forelse($order->payments as $payment)<div class="confirmed-payment"><div><strong>{{ $payment->method==='gcash'?'GCash':ucfirst($payment->method) }}</strong><small>{{ $payment->confirmed_at?->format('M j, g:i a') }}</small>@if($payment->reference)<small>Ref: {{ $payment->reference }}</small>@endif</div><strong>₱{{ number_format($payment->amount/100,2) }}</strong></div>@empty<p class="muted" style="margin-top:15px">No payments recorded yet.</p>@endforelse</section></aside></div>
@endif
</main></div>