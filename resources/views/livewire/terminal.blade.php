@assets
<link rel="stylesheet" href="{{ asset('branding/staff-pin.css') }}">
<link rel="stylesheet" href="{{ asset('branding/cashier-roomy.css') }}">
<link rel="stylesheet" href="{{ asset('branding/register-shift.css') }}">
<link rel="stylesheet" href="{{ asset('branding/pos-adjustments.css') }}">
<link rel="stylesheet" href="{{ asset('branding/pos-checkout-layout.css') }}">
@endassets
<div class="app-shell" x-data="{navOpen:false,cartOpen:false,payStep:false}"><x-sidebar :business="$business" :kiosk="$kiosk" mode="pos"/><main class="main terminal-main cashier-roomy-pos"><header class="topbar"><div class="heading-row"><button class="mobile-only icon-btn" @click="navOpen=true" aria-label="Open navigation"><x-g-icon name="bars-3"/></button><div><span class="eyebrow">SELL SOMETHING GOOD</span><h1>Point of sale<span class="brand-dot">.</span></h1></div></div><div class="topbar-actions"><button class="btn small" wire:poll.10s wire:click="$set('incomingOpen',true)">Incoming orders · {{ $incoming->count() }}</button><span class="branch-chip desktop-branch"><span class="tiny-dot"></span>{{ $kiosk->name }}</span>
<label class="staff-branch">Kiosk<select @change="window.location.assign($event.target.value)">
    @foreach(\App\Services\Access::kiosks(auth()->user(), $business->id)->where('active', true) as $branch)
        <option value="{{ route('pos', [$business, $branch]) }}" @selected($branch->is($kiosk))>{{ $branch->name }}</option>
    @endforeach
</select></label><span class="date-label">{{ now()->format('D, M j') }}</span></div></header>@if(\App\Services\Access::allows(auth()->user(), $business->id, 'orders.pay'))
<x-register-shift-controls :shift="$registerShift" :drawer-totals="$drawerTotals" :cash-counts="$cashCounts"/>
@endif
<div class="pos-pin-links">
<button type="button" class="btn" data-cashier-install>Install Cashier POS</button>
    @if(\App\Services\StaffPins::canManage(auth()->user(), $business, $kiosk))
        <a class="btn" href="{{ route('cashier.access', [$business, $kiosk]) }}">Cashier access</a>
    @endif
    @if(\App\Services\Access::membership(auth()->user(), $business->id)?->pivot->role === 'cashier' && \App\Services\StaffPins::hashFor($business->id, auth()->id()))
        <div x-data x-init="@if($errors->hasAny(['current_pin','pin'])) $refs.changePin.showModal() @endif">
            <button class="btn" type="button" @click="$refs.changePin.showModal()">Change my PIN</button>
            <dialog class="gather-dialog gd-content pin-approval" x-ref="changePin" wire:ignore.self aria-labelledby="change-cashier-pin-title" @close="$refs.changePin.querySelector('form').reset()">
                <div class="section-line"><h2 id="change-cashier-pin-title">Change my PIN</h2><button type="button" class="icon-btn" @click="$refs.changePin.close()" aria-label="Dismiss PIN change"><x-g-icon name="x-mark"/></button></div>
                <x-change-cashier-pin-form :business="$business" :kiosk="$kiosk" :dialog="true"/>
            </dialog>
        </div>
    @endif
</div>
@if(session('pin_status'))<p role="status">{{ session('pin_status') }}</p>@endif
<x-manager-adjustment :proposal="$pendingAdjustment" :business="$business" :kiosk="$kiosk"/>
<div class="register-sale-area {{ $registerShift ? '' : 'register-sale-closed' }}">
@if(!$registerShift)
<section class="card register-lock">
    <h2>Open a shift to start selling</h2><p>Enter your starting cash to open the register.</p>
    @if(\App\Services\Access::allows(auth()->user(), $business->id, 'orders.pay'))
        <button type="button" class="btn primary" @click="$dispatch('register-open-requested')">Open Shift</button>
    @endif
</section>
@endif
<div @if(!$registerShift) class="register-inactive-catalog" inert aria-hidden="true" @endif>
<div class="terminal-layout" :class="{'staff-cart-open':cartOpen,'staff-pay-step':payStep}" @keydown.escape.window="cartOpen=false">
<button type="button" class="btn primary staff-cart-trigger" @click="cartOpen=true;payStep=false;$nextTick(() => $refs.cartClose.focus())" :aria-expanded="cartOpen.toString()" aria-controls="staff-cart">
<x-g-icon name="shopping-bag"/>View cart · ₱{{ number_format($totalDue/100,2) }}</button>
<button type="button" class="staff-cart-shade" aria-label="Close cart" tabindex="-1" @click="cartOpen=false"></button><section class="catalog">
<label class="staff-order-mode">Order type or table
<select wire:model.live="tableId">
    <option value="">Takeout</option>
    @foreach($kiosk->tables()->where('active', true)->get() as $table)
        <option value="{{ $table->id }}">Dine-in · {{ $table->name }}</option>
    @endforeach
</select></label>
<div class="search"><x-g-icon name="magnifying-glass"/><input wire:model.live.debounce.200ms="search" placeholder="Search your menu…" aria-label="Search menu"><kbd>⌕</kbd></div><div class="categories"><button wire:click="$set('category','All')" class="chip {{ $category==='All'?'selected':'' }}">All items</button>@foreach($categories as $c)<button wire:click="$set('category',{{ Js::from($c) }})" class="chip {{ $category===$c?'selected':'' }}">{{ $c }}</button>@endforeach</div><div class="section-line"><h2>Made to order</h2><span>{{ $products->count() }} items available</span></div><div class="product-grid">@forelse($products as $p)<button wire:key="product-{{ $p->id }}" wire:click="add({{ $p->id }})" class="product-card" @disabled($p->pivot->stock<1)><div class="product-photo"><img src="{{ $p->image }}" alt="{{ $p->name }}"><span class="stock-tag">{{ $p->pivot->stock>0?'Freshly made':'Sold out' }}</span></div><div class="product-info"><span class="product-category">{{ $p->category }}</span><h3>{{ $p->name }}</h3><div class="product-bottom"><strong>₱{{ number_format($p->price/100,2) }}</strong><span class="add-circle"><span class="cashier-desktop-add">+</span><span class="cashier-mobile-add">{{ $p->pivot->stock > 0 ? 'Add' : 'Sold out' }}</span></span></div></div></button>@empty<div class="empty">No matching items.</div>@endforelse</div></section><aside class="cart-panel" id="staff-cart" aria-label="Current order">
<div class="staff-cart-tools"><button type="button" class="btn" x-show="payStep" @click="payStep=false; $nextTick(() => $refs.continuePayment.focus())">Back to cart</button><button type="button" class="icon-btn" x-ref="cartClose" @click="cartOpen=false" aria-label="Close cart"><x-g-icon name="x-mark"/></button></div>
<div class="cart-head"><span class="eyebrow">A GOOD CHOICE</span><h2 x-text="payStep ? 'Order summary' : 'Current order'">Current order</h2><div class="cart-mode"><span><x-g-icon name="user"/>{{ $tableId?'Dine-in':'Takeout' }}</span><select wire:model.live="tableId" aria-label="Select table"><option value="">Takeout</option>@foreach($kiosk->tables()->where('active',true)->get() as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div></div><div class="cart-items">@forelse($cartProducts as $p)<div class="cart-item" wire:key="cart-{{ $p->id }}"><img src="{{ $p->image }}" alt=""><div><h3>{{ $p->name }}</h3>
@if(in_array($p->id, $complimentaryIds, true))
    <span class="badge">Complimentary</span>
@endif
<div class="quantity"><button wire:click="decrease({{ $p->id }})" aria-label="Remove one {{ $p->name }}">−</button><span>{{ $cart[$p->id] }}</span><button wire:click="add({{ $p->id }})" aria-label="Add one {{ $p->name }}">+</button></div></div><strong>
@if(in_array($p->id, $complimentaryIds, true))
    <del>₱{{ number_format($p->price*$cart[$p->id]/100,2) }}</del> ₱0.00
@else
    ₱{{ number_format($p->price*$cart[$p->id]/100,2) }}
@endif
</strong></div>@empty<div class="cart-empty"><span class="orange-icon"><x-g-icon name="shopping-bag"/></span><h3>Something delicious awaits.</h3><p>Tap a menu item to start an order.</p></div>@endforelse
<div x-show="!payStep">
    @include('livewire.partials.topping-picker', ['canIncrease' => (bool) $registerShift, 'canEdit' => !$complimentaryIds && $discountValue === ''])
</div>
<div x-show="payStep">
    @foreach($toppings as $topping)
        @if($quantity = $toppingQuantities[$topping->id] ?? 0)
            <div class="total-row">
                <span>{{ $quantity }} × {{ $topping->name }}</span>
                <strong>{{ $topping->active ? '₱'.number_format($topping->price*$quantity/100, 2) : 'Unavailable' }}</strong>
            </div>
        @endif
    @endforeach
</div>
</div><div class="cart-checkout"><div class="checkout-totals"><div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div>
@include('livewire.partials.topping-subtotal-breakdown')
@if($complimentary > 0)<div class="total-row"><span>Complimentary items</span><strong>−₱{{ number_format($complimentary / 100, 2) }}</strong></div>@endif
@if($discount > 0)<div class="total-row"><span>Discount</span><strong>−₱{{ number_format($discount / 100, 2) }}</strong></div>@endif
<div class="total-row"><span>Service charge ({{ $kiosk->service_charge_bps/100 }}%)</span><span>₱{{ number_format($fee/100,2) }}</span></div><div class="total-row grand-total"><strong>Order total</strong><strong>₱{{ number_format($totalDue/100,2) }}</strong></div></div>@if($cart && \App\Services\Access::allows(auth()->user(), $business->id, 'orders.pay'))
<details class="pos-adjustments" wire:key="pos-adjustments" wire:ignore.self>
    <summary>Order adjustments</summary>
    <p>Choose one adjustment type per order.</p>
    @if($discountValue !== '')
        <p><strong>Discount applied</strong> · Manager approved</p>
        <button type="button" class="btn" wire:click="requestCartDiscount(true)">Remove discount</button>
        <p class="muted">Remove the discount to make an item free.</p>
    @elseif($complimentaryIds)
        <p><strong>Complimentary items applied</strong> · Manager approved</p>
        <button type="button" class="btn" disabled>Apply discount</button>
        <p class="muted">Undo all free items to apply a discount.</p>
    @else
        <label>Discount type<select wire:model="proposedDiscountType"><option value="percent">Percentage (%)</option><option value="fixed">Fixed amount (₱)</option></select></label>
        <label>Discount<input type="text" inputmode="decimal" maxlength="10" placeholder="0.00" wire:model="proposedDiscountValue"></label>
        <button type="button" class="btn" wire:click="requestCartDiscount">Review discount</button>
    @endif
    @foreach($cartProducts as $product)
        <div class="pos-adjustment-item" wire:key="adjustment-{{ $product->id }}">
            <strong>{{ $product->name }}</strong>
            <button type="button" class="btn" wire:click="requestCartComplimentary({{ $product->id }})" @disabled($discountValue !== '')>
                {{ in_array($product->id, $complimentaryIds, true) ? 'Undo free item' : 'Make item free' }}
            </button>
        </div>
    @endforeach
</details>
@endif
<button type="button" class="btn primary wide staff-next-payment" x-ref="continuePayment" @click="payStep=true;$nextTick(() => $refs.paymentHeading.focus())" @disabled(!$cart || !$adjustmentsValid)>{{ $totalDue === 0 && $cart ? 'Review free order' : 'Continue to payment' }}</button>
@if(!$tableId)
    <button type="button" class="btn wide staff-pay-at-pickup" wire:click="payAtPickup" wire:loading.attr="disabled" wire:target="checkout,payAtPickup" @disabled(!$cart || !$adjustmentsValid)>Pay at pickup</button>
@endif
<div class="checkout-errors">
@foreach($errors->all() as $error)
    <p class="error" role="alert">{{ $error }}</p>
@endforeach
</div>
<div class="staff-payment">
@if($totalDue === 0 && $cart)
    <h2 class="checkout-payment-heading" tabindex="-1" x-ref="paymentHeading">No payment due</h2>
    <button type="button" class="btn primary wide charge" wire:click="checkout(true)" wire:loading.attr="disabled" @disabled(!$adjustmentsValid)>Confirm free order</button>
@else
<h2 class="checkout-payment-heading" tabindex="-1" x-ref="paymentHeading">Payment method</h2><div class="payment-options">@foreach(['cash'=>'banknotes','card'=>'credit-card','gcash'=>'device-phone-mobile'] as $m=>$i)<button wire:click="$set('payment','{{ $m }}')" class="{{ $payment===$m?'selected':'' }}" aria-pressed="{{ $payment===$m ? 'true' : 'false' }}"><x-g-icon :name="$i"/>{{ $m==='gcash'?'GCash':ucfirst($m) }}</button>@endforeach</div>
@if($payment === 'cash')
<x-cash-change received="cashReceived" :due="$totalDue" wire:key="cash-change-{{ $totalDue }}" />
@endif
<button class="btn wide split-toggle {{ $payment==='split'?'is-active':'' }}" wire:click="$set('payment','split')"><x-g-icon name="squares-plus"/>Split payment</button>
@if($payment==='split')<div class="pos-split-rows">@foreach($paymentRows as $i=>$row)<div class="pos-split-row" wire:key="tender-{{ $i }}"><label>Method {{ $i+1 }}<select wire:model.live="paymentRows.{{ $i }}.method"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="card">Card</option></select></label><label>Amount (PHP)<input type="text" inputmode="decimal" wire:model="paymentRows.{{ $i }}.amount" placeholder="0.00"></label>
@if(($row['method'] ?? null) === 'cash')
<x-cash-change received="paymentRows.{{ $i }}.cash_received" amount="paymentRows.{{ $i }}.amount" />
@endif
@if(count($paymentRows)>2)<button class="icon-btn" wire:click="removeTender({{ $i }})" aria-label="Remove payment"><x-g-icon name="x-mark"/></button>@endif</div>@endforeach<button class="text-link" wire:click="addTender" @disabled(count($paymentRows)>=8)>+ Add payment</button></div><p class="payment-note">Split amounts must add up to the full total. Verify every payment before charging.</p>@else<p class="payment-note">{{ $payment==='cash'?'Collect cash before confirming.':'Confirm payment on your external terminal before charging.' }}</p>@endif<button wire:key="checkout-{{ $payment }}-{{ $totalDue }}-{{ (int) $adjustmentsValid }}" wire:click="checkout(true)" wire:loading.attr="disabled" wire:target="checkout,payAtPickup" class="btn primary wide charge" @disabled(!$cart || !$adjustmentsValid) @if($payment === 'cash') :disabled="!{{ $cart && $adjustmentsValid ? 'true' : 'false' }} || !/^[0-9]{1,7}([.][0-9]{1,2})?$/.test($wire.cashReceived) || Math.round(Number($wire.cashReceived) * 100) < {{ $totalDue }}" @endif>{{ $payment === 'cash' ? 'Confirm cash payment' : 'Charge ₱'.number_format($totalDue/100,2) }} <x-g-icon name="arrow-right"/></button>
@endif
</div></div></aside></div></div></div>
@if($receipt)<span hidden x-init="cartOpen=false;payStep=false"></span>@endif
@if($receipt)<dialog class="modal gather-dialog gd-content receipt-success" aria-label="Order confirmed" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.set('receipt',null)"><span class="success-icon"><x-g-icon name="check"/></span><h2>Order confirmed.</h2><p class="muted">{{ $receiptUnpaid ? 'Order sent to the kitchen. Collect payment at pickup from Incoming orders.' : ($receiptFree ? 'No payment due. The kitchen can now see this order.' : 'Payment recorded. The kitchen can now see this order.') }}</p><a class="btn primary" href="{{ route('tracking',$receipt) }}" target="_blank">View receipt & status ↗</a><button class="btn" wire:click="$set('receipt',null)">Start next order</button></dialog>@endif@if($incomingOpen)<dialog class="modal gather-dialog gd-content gd-wide" aria-label="Incoming orders" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.set('incomingOpen',false)"><div class="section-line"><h2>Incoming orders</h2><button class="icon-btn" wire:click="$set('incomingOpen',false)" aria-label="Close incoming orders"><x-g-icon name="x-mark"/></button></div><p class="muted" style="margin-bottom:20px">Takeout and table orders appear here, including partially paid orders. Open Collect / split payment to record payments.</p><x-order-table :orders="$incoming" :business="$business"/></dialog>@endif</main></div>