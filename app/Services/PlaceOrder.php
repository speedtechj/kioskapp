<?php

namespace App\Services;

use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceOrder
{
    public static function adjustmentFingerprint(array $cart, array $prices, int $serviceRate): string
    {
        $cart = array_map('intval', $cart);
        $prices = array_map('intval', $prices);
        ksort($cart);
        ksort($prices);

        return hash('sha256', json_encode([$cart, $prices, $serviceRate], JSON_THROW_ON_ERROR));
    }

    public function handle(Kiosk $kiosk, array $cart, string $requestKey, ?DiningTable $table = null, ?string $method = null, ?string $name = null, ?string $notes = null, ?array $payments = null, bool $payAtPickup = false, ?string $cashReceived = null, array $complimentaryIds = [], string $discountType = 'percent', string $discountValue = '', array $adjustmentApprovals = [], string $approvedCart = ''): Order
    {
        abort_unless($kiosk->active, 404);
        if ($table) {
            abort_unless($table->kiosk_id === $kiosk->id && $table->active, 404);
        }
        abort_if($payAtPickup && ($table !== null || $method !== null || $payments !== null), 422);
        $isPos = $payAtPickup || $method !== null || $payments !== null;
        abort_if(! $isPos && ($complimentaryIds !== [] || $discountValue !== ''), 403);
        validator(['complimentary' => $complimentaryIds], ['complimentary' => 'array|max:50', 'complimentary.*' => 'integer|distinct|min:1'])->validate();
        $complimentaryIds = array_map('intval', $complimentaryIds);
        if (array_diff($complimentaryIds, array_keys($cart))) {
            throw ValidationException::withMessages(['cart' => 'Complimentary items must be in the cart.']);
        }
        if ($isPos) {
            Access::authorize($kiosk->business_id, 'orders.pay', $kiosk->id);
            Access::authorize($kiosk->business_id, 'pos.use', $kiosk->id);
            abort_unless($payAtPickup || $payments !== null || in_array($method, ['cash', 'card', 'gcash'], true), 422);
        }
        validator(['cart' => $cart, 'request_key' => $requestKey, 'name' => $name, 'notes' => $notes], ['cart' => 'required|array|min:1|max:50', 'cart.*' => 'required|integer|min:1|max:99', 'request_key' => 'required|uuid', 'name' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:500'])->validate();

        foreach (array_keys($cart) as $id) {
            if (! ctype_digit((string) $id) || (string) (int) $id !== (string) $id || (int) $id < 1) {
                throw ValidationException::withMessages(['cart' => 'Invalid product identifier. Please rebuild your basket.']);
            }
        }

        return DB::transaction(function () use ($kiosk, $cart, $requestKey, $table, $method, $name, $notes, $payments, $isPos, $payAtPickup, $cashReceived, $complimentaryIds, $discountType, $discountValue, $adjustmentApprovals, $approvedCart) {
            BusinessAvailability::enforce($kiosk->business_id, lock: true);
            $locked = Kiosk::whereKey($kiosk->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->active, 404);
            $existing = Order::where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless($existing->kiosk_id === $kiosk->id && $existing->dining_table_id === $table?->id && $existing->source === ($isPos ? 'pos' : 'qr'), 409);

                return $existing;
            }
            if ($isPos) {
                RegisterShifts::requireOpen($kiosk->id);
            }
            $products = $kiosk->visibleProducts()->where('business_id', $kiosk->business_id)->where('active', true)->whereIn('products.id', array_keys($cart))->orderBy('products.id')->lockForUpdate()->get();
            if ($products->count() !== count($cart)) {
                throw ValidationException::withMessages(['cart' => 'An item is no longer available. Please refresh your basket.']);
            }
            if ($complimentaryIds && $discountValue !== '') {
                throw ValidationException::withMessages(['adjustment' => 'Choose either a discount or complimentary items.']);
            }
            if (($complimentaryIds || $discountValue !== '') && (! $adjustmentApprovals
                || $approvedCart !== self::adjustmentFingerprint($cart, $products->pluck('price', 'id')->all(), $locked->service_charge_bps))) {
                throw ValidationException::withMessages(['adjustment' => 'Get manager approval for the current cart before checkout.']);
            }

            $subtotal = 0;
            $items = [];
            $complimentary = 0;
            foreach ($products as $product) {
                $qty = (int) $cart[$product->id];
                if ($qty > $product->pivot->stock) {
                    throw ValidationException::withMessages(['cart' => "Not enough stock for {$product->name}."]);
                }
                $total = $product->price * $qty;
                $subtotal += $total;
                $isComplimentary = in_array($product->id, $complimentaryIds, true);
                $complimentary += $isComplimentary ? $total : 0;
                $items[] = ['product_id' => $product->id, 'name' => $product->name, 'unit_price' => $product->price, 'quantity' => $qty, 'total' => $isComplimentary ? 0 : $total, 'complimentary' => $isComplimentary];
            }
            ['discount' => $discount, 'fee' => $fee, 'total' => $totalDue] = OrderAdjustments::totals(
                $subtotal, $complimentary, $discountType, $discountValue, $locked->service_charge_bps,
            );
            $order = Order::create(['token' => (string) Str::uuid(), 'request_key' => $requestKey, 'business_id' => $kiosk->business_id, 'kiosk_id' => $kiosk->id, 'dining_table_id' => $table?->id, 'user_id' => $isPos ? auth()->id() : null, 'source' => $isPos ? 'pos' : 'qr', 'payment_status' => $isPos && $totalDue === 0 ? 'paid' : 'unpaid', 'payment_method' => null, 'subtotal' => $subtotal, 'complimentary_amount' => $complimentary, 'discount_amount' => $discount, 'service_charge' => $fee, 'total' => $totalDue, 'customer_name' => $name, 'notes' => $notes]);
            if ($adjustmentApprovals) {
                $order->forceFill(['adjustment_approvals' => $adjustmentApprovals])->save();
            }
            $order->items()->createMany($items);
            foreach ($products as $product) {
                $kiosk->products()->updateExistingPivot($product->id, ['stock' => $product->pivot->stock - (int) $cart[$product->id]]);
            }

            if ($isPos && ! $payAtPickup && $totalDue > 0) {
                $rows = $payments ?? [['method' => $method, 'amount' => Money::input($totalDue)]];
                if ($method === 'cash' && $cashReceived !== null && $payments === null) {
                    $rows[0]['cash_received'] = $cashReceived;
                }
                $normalized = RecordPayment::normalize($rows);
                if (array_sum(array_column($normalized, 'amount')) !== $totalDue) {
                    throw ValidationException::withMessages(['payments' => 'The split payments must add up to the full order total.']);
                }
                app(RecordPayment::class)->handle($order, $rows, $requestKey);
            }

            return $order->fresh();
        });
    }
}
