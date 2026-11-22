<?php

namespace App\Livewire;

use App\Livewire\Concerns\ApprovesOrderAdjustments;
use App\Livewire\Concerns\ChecksBusinessAvailability;
use App\Livewire\Concerns\ManagesRegisterShift;
use App\Livewire\Concerns\SelectsToppings;
use App\Models\Business;
use App\Models\Kiosk;
use App\Models\Order;
use App\Services\Access;
use App\Services\OrderAdjustments;
use App\Services\OrderToppings;
use App\Services\PlaceOrder;
use App\Models\MenuPromotion;
use App\Services\RecordPayment;
use App\Services\RegisterShifts;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Terminal extends Component
{
    use ApprovesOrderAdjustments;
    use ChecksBusinessAvailability;
    use ManagesRegisterShift;
    use SelectsToppings;

    #[Locked]
    public ?int $quotedPromo = null;

    #[Locked]
    public int $businessId;

    #[Locked]
    public int $kioskId;

    #[Locked]
    public string $requestKey;

    public bool $incomingOpen = false;

    public array $cart = [];

    public string $search = '';

    public string $category = 'All';

    public string $payment = 'cash';

    #[Locked]
    public array $complimentaryIds = [];

    #[Locked]
    public array $complimentaryToppingIds = [];

    #[Locked]
    public string $discountType = 'percent';

    #[Locked]
    public string $discountValue = '';

    public string $proposedDiscountType = 'percent';

    public string $proposedDiscountValue = '';

    #[Locked]
    public array $adjustmentApprovals = [];

    #[Locked]
    public string $approvedCart = '';

    public string $cashReceived = '';

    public array $paymentRows = [['method' => 'gcash', 'amount' => ''], ['method' => 'cash', 'amount' => '']];

    public ?int $tableId = null;

    public ?string $receipt = null;

    #[Locked]
    public bool $receiptUnpaid = false;

    #[Locked]
    public bool $receiptFree = false;

    public function mount(Business $business, Kiosk $kiosk): void
    {
        abort_unless($kiosk->business_id === $business->id, 404);
        $this->businessId = $business->id;
        $this->kioskId = $kiosk->id;
        $this->requestKey = (string) Str::uuid();
        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
    }

    protected function toppingBusinessId(): int
    {
        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
        $this->ensureCartEditable();

        return $this->businessId;
    }

    public function add(int $id): void
    {
        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
        RegisterShifts::requireOpen($this->kioskId);
        $product = Kiosk::findOrFail($this->kioskId)->visibleProducts()->where('business_id', $this->businessId)->where('active', true)->findOrFail($id);
        $qty = ($this->cart[$id] ?? 0) + 1;
        if ($qty <= $product->pivot->stock && $qty <= 99) {
            $this->ensureCartEditable();
            $this->cart[$id] = $qty;
        }
    }

    public function decrease(int $id): void
    {
        if (isset($this->cart[$id])) {
            $this->ensureCartEditable();
            if (--$this->cart[$id] <= 0) {
                unset($this->cart[$id]);
                if (! $this->cart) {
                    $this->toppingQuantities = [];
                }
                $this->complimentaryIds = array_values(array_diff($this->complimentaryIds, [$id]));
            }
        }
    }

    public function requestCartDiscount(bool $remove = false): void
    {
        $this->authorizeAdjustment();
        if ($this->complimentaryToppingIds || $this->complimentaryIds || ($remove !== ($this->discountValue !== ''))) {
            throw ValidationException::withMessages(['adjustment' => 'Remove the current adjustment before switching types.']);
        }
        $type = $remove ? 'percent' : $this->proposedDiscountType;
        $value = $remove ? '' : $this->proposedDiscountValue;
        if (! $remove && (! in_array($type, ['percent', 'fixed'], true) || $value === '')) {
            throw ValidationException::withMessages(['discountValue' => 'Enter a percentage or fixed discount.']);
        }
        $label = $remove ? 'Remove discount' : ($type === 'percent' ? $value.'% discount' : '₱'.$value.' discount');
        $this->reviewAdjustment($this->cartProposal($type, $value, $this->complimentaryIds, $label));
    }

    public function requestCartComplimentary(int $id): void
    {
        $this->authorizeAdjustment();
        abort_unless(isset($this->cart[$id]), 422);
        if ($this->discountValue !== '') {
            throw ValidationException::withMessages(['adjustment' => 'Remove the discount before making an item free.']);
        }
        $product = Kiosk::findOrFail($this->kioskId)->visibleProducts()->findOrFail($id);
        $free = ! in_array($id, $this->complimentaryIds, true);
        $ids = $free ? [...$this->complimentaryIds, $id] : array_values(array_diff($this->complimentaryIds, [$id]));
        $this->reviewAdjustment($this->cartProposal('percent', '', $ids, ($free ? 'Make complimentary: ' : 'Undo complimentary: ').$product->name));
    }

    public function requestCartComplimentaryTopping(int $id): void
    {
        $this->authorizeAdjustment();
        abort_unless(isset($this->toppingQuantities[$id]), 422);
        if ($this->discountValue !== '') {
            throw ValidationException::withMessages(['adjustment' => 'Remove the discount before making a topping free.']);
        }

        $topping = OrderToppings::catalog($this->businessId)->where('active', true)->find($id);
        abort_unless($topping, 404);
        $free = ! in_array($id, $this->complimentaryToppingIds, true);
        $ids = $free ? [...$this->complimentaryToppingIds, $id] : array_values(array_diff($this->complimentaryToppingIds, [$id]));
        $label = ($free ? 'Make topping complimentary: ' : 'Undo complimentary topping: ').$topping->name;
        $this->reviewAdjustment($this->cartProposal('percent', '', $this->complimentaryIds, $label, $ids));
    }

    private function authorizeAdjustment(): void
    {
        Access::authorize($this->businessId, 'orders.pay', $this->kioskId);
        RegisterShifts::requireOpen($this->kioskId);
    }

    private function ensureCartEditable(): void
    {
        if ($this->discountValue !== '' || $this->complimentaryIds || $this->complimentaryToppingIds) {
            throw ValidationException::withMessages(['cart' => 'Remove the approved adjustment before changing items or quantities.']);
        }
    }

    private function cartProposal(string $type, string $value, array $ids, string $label, ?array $toppingIds = null): array
    {
        $kiosk = Kiosk::findOrFail($this->kioskId);
        $products = $kiosk->visibleProducts()->where('active', true)->whereIn('products.id', array_keys($this->cart))->get();
        if ($products->isEmpty() || $products->count() !== count($this->cart)) {
            throw ValidationException::withMessages(['adjustment' => 'Add available items before requesting an adjustment.']);
        }
        $lineTotal = fn ($product) => $product->price * (int) $this->cart[$product->id];
        $toppingLines = OrderToppings::lines($this->businessId, $this->toppingQuantities);
        $toppingIds ??= $this->complimentaryToppingIds;
        $toppingComp = fn (array $ids) => collect($toppingLines)->whereIn('id', $ids)->sum('total');
        $subtotal = $products->sum($lineTotal) + array_sum(array_column($toppingLines, 'total'));
        $promotion = MenuPromotion::visibleFor($kiosk->business_id);
        $promoSaving = $promotion?->savings($products, $this->cart) ?? 0;
        $beforePromo = ($this->discountValue !== '' || $this->complimentaryIds || $this->complimentaryToppingIds) ? 0 : $promoSaving;
        $afterPromo = ($value !== '' || $ids || $toppingIds) ? 0 : $promoSaving;
        $before = OrderAdjustments::totals($subtotal - $beforePromo, $products->whereIn('id', $this->complimentaryIds)->sum($lineTotal) + $toppingComp($this->complimentaryToppingIds), $this->discountType, $this->discountValue, $kiosk->service_charge_bps)['total'];
        $after = OrderAdjustments::totals($subtotal - $afterPromo, $products->whereIn('id', $ids)->sum($lineTotal) + $toppingComp($toppingIds), $type, $value, $kiosk->service_charge_bps);
        if ($value !== '' && $after['discount'] === 0) {
            throw ValidationException::withMessages(['discountValue' => 'Enter a discount greater than zero.']);
        }

        $prices = $products->pluck('price', 'id')->all();

        return [
            'label' => $label, 'before' => $before, 'after' => $after['total'],
            'type' => $type, 'value' => $value, 'ids' => $ids, 'toppingIds' => $toppingIds,
            'fingerprint' => PlaceOrder::adjustmentFingerprint($this->cart, $prices, $kiosk->service_charge_bps, PlaceOrder::toppingsForApproval($toppingLines, $toppingIds)),
        ];
    }

    private function commitAdjustment(array $proposal, array $approval): void
    {
        $this->authorizeAdjustment();
        if ($proposal !== $this->cartProposal($proposal['type'], $proposal['value'], $proposal['ids'], $proposal['label'], $proposal['toppingIds'] ?? [])) {
            throw ValidationException::withMessages(['adjustment' => 'The order changed. Review the adjustment again.']);
        }

        $this->discountType = $proposal['type'];
        $this->discountValue = $proposal['value'];
        $this->complimentaryIds = $proposal['ids'];
        $this->complimentaryToppingIds = $proposal['toppingIds'];
        $this->approvedCart = $proposal['fingerprint'];
        $this->adjustmentApprovals[] = $approval + ['action' => $proposal['label'], 'before' => $proposal['before'], 'after' => $proposal['after']];
        $this->reset('proposedDiscountType', 'proposedDiscountValue');
    }

    public function checkout(bool $requireCashReceived = false): void
    {
        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
        $this->validate(['payment' => 'required|in:cash,card,gcash,split']);
        $kiosk = Kiosk::findOrFail($this->kioskId);
        $table = $this->tableId ? $kiosk->tables()->where('active', true)->findOrFail($this->tableId) : null;
        $method = $this->payment === 'split' ? null : $this->payment;
        $rows = $this->payment === 'split' ? $this->paymentRows : null;
        if ($requireCashReceived && $rows !== null) {
            $rows = RecordPayment::withCashReceived($rows);
        }
        $cashReceived = $method === 'cash' && ($requireCashReceived || $this->cashReceived !== '') ? $this->cashReceived : null;
        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, $table, $method, payments: $rows, cashReceived: $cashReceived, complimentaryIds: $this->complimentaryIds, discountType: $this->discountType, discountValue: $this->discountValue, adjustmentApprovals: $this->adjustmentApprovals, approvedCart: $this->approvedCart, toppings: $this->toppingQuantities, complimentaryToppingIds: $this->complimentaryToppingIds, quotedPromo: $this->quotedPromo);
        $this->finishCheckout($order);
    }

    public function payAtPickup(): void
    {
        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
        if ($this->tableId !== null) {
            throw ValidationException::withMessages(['cart' => 'Select Takeout to pay at pickup.']);
        }

        $order = app(PlaceOrder::class)->handle(
            Kiosk::findOrFail($this->kioskId),
            $this->cart,
            $this->requestKey,
            payAtPickup: true,
            complimentaryIds: $this->complimentaryIds,
            discountType: $this->discountType,
            discountValue: $this->discountValue,
            adjustmentApprovals: $this->adjustmentApprovals,
            approvedCart: $this->approvedCart,
            toppings: $this->toppingQuantities,
            complimentaryToppingIds: $this->complimentaryToppingIds,
            quotedPromo: $this->quotedPromo,
        );
        $this->finishCheckout($order);
    }

    private function finishCheckout(Order $order): void
    {
        $this->receipt = $order->slug;
        $this->receiptUnpaid = $order->payment_status === 'unpaid';
        $this->receiptFree = (int) $order->total === 0;
        $this->cart = [];
        $this->toppingQuantities = [];
        $this->reset('cashReceived', 'complimentaryIds', 'complimentaryToppingIds', 'discountType', 'discountValue', 'proposedDiscountType', 'proposedDiscountValue', 'adjustmentApprovals', 'approvedCart');
        $this->resetValidation();
        $this->paymentRows = [['method' => 'gcash', 'amount' => ''], ['method' => 'cash', 'amount' => '']];
        $this->requestKey = (string) Str::uuid();
    }

    public function addTender(): void
    {
        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
        if (count($this->paymentRows) < 8) {
            $this->paymentRows[] = ['method' => 'cash', 'amount' => ''];
        }
    }

    public function removeTender(int $index): void
    {
        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
        if (count($this->paymentRows) > 2) {
            unset($this->paymentRows[$index]);
            $this->paymentRows = array_values($this->paymentRows);
        }
    }

    public function markPaid(int $id): void
    {
        Access::authorize($this->businessId, 'orders.pay', $this->kioskId);
        $o = Order::where('business_id', $this->businessId)->where('kiosk_id', $this->kioskId)->findOrFail($id);
        app(RecordPayment::class)->settleCash($o);
    }

    public function render()
    {
        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
        $business = Business::findOrFail($this->businessId);
        $kiosk = Kiosk::findOrFail($this->kioskId);
        $incoming = $kiosk->orders()->whereIn('payment_status', ['unpaid', 'partial', 'refund_pending'])->with('items', 'table', 'kiosk', 'payments')->latest()->limit(50)->get();
        $all = $kiosk->visibleProducts()->where('business_id', $business->id)->where('active', true)->get();
        $products = $all->filter(fn ($p) => ($this->category === 'All' || $p->category === $this->category) && str_contains(strtolower($p->name), strtolower($this->search)));
        $categories = $all->pluck('category')->unique();
        $cartProducts = $all->whereIn('id', array_keys($this->cart));
        $toppings = $this->cart ? $this->toppingOptions($kiosk->business_id) : collect();
        $toppingSubtotal = $this->toppingSubtotal($toppings);
        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0)) + $toppingSubtotal;
        $complimentary = $cartProducts->whereIn('id', $this->complimentaryIds)
            ->sum(fn ($product) => $product->price * ($this->cart[$product->id] ?? 0));
        $complimentary += $this->toppingSubtotal($toppings->whereIn('id', $this->complimentaryToppingIds));
        $promotion = MenuPromotion::visibleFor($business->id);
        $promoItems = $promotion ? $all->filter($promotion->eligible(...)) : collect();
        $promoPhase = $promoItems->isNotEmpty() ? $promotion->phase() : null;
        $promoSaving = $this->quotedPromo = ($this->discountValue !== '' || $this->complimentaryIds || $this->complimentaryToppingIds)
            ? 0 : ($promotion?->savings($cartProducts, $this->cart) ?? 0);
        $adjustmentsValid = true;
        try {
            $totals = OrderAdjustments::totals($subtotal - $promoSaving, $complimentary, $this->discountType, $this->discountValue, $kiosk->service_charge_bps);
        } catch (ValidationException) {
            $adjustmentsValid = false;
            $totals = OrderAdjustments::totals($subtotal - $promoSaving, $complimentary, 'percent', '', $kiosk->service_charge_bps);
        }
        ['discount' => $discount, 'fee' => $fee, 'total' => $totalDue] = $totals;

        $registerShift = RegisterShifts::active($this->kioskId);

        return view('livewire.terminal', compact('promotion', 'promoItems', 'promoPhase', 'promoSaving', 'toppings', 'toppingSubtotal', 'business', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'incoming', 'discount', 'complimentary', 'totalDue', 'adjustmentsValid', 'registerShift'))->layout('layouts.app', ['title' => 'Point of sale']);
    }
}
