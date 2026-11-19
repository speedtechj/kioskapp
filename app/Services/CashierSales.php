<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Support\Carbon;

class CashierSales
{
    public function report(int $businessId, array $kioskIds, string $date, int $cashierId, int $shiftId): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $date);
        $end = $start->copy()->addDay();

        $orderQuery = Order::query()->where('business_id', $businessId)->whereIn('kiosk_id', $kioskIds);
        $paymentQuery = OrderPayment::query()->whereIn('order_id', (clone $orderQuery)->select('orders.id'))
            ->where('confirmed_at', '>=', $start)->where('confirmed_at', '<', $end);

        $shiftQuery = RegisterShift::query()->whereIn('kiosk_id', $kioskIds)
            ->where('opened_at', '<', $end)->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $start));
        $shifts = $shiftQuery->latest('opened_at')->orderByDesc('id')->get();
        $visibleShifts = $shiftId ? $shifts->where('id', $shiftId) : $shifts;
        $cashierIds = (clone $paymentQuery)->whereNotNull('recorded_by')->distinct()->pluck('recorded_by');
        $cashiers = User::query()->whereIn('id', $cashierIds)->orderBy('name')->get(['id', 'name']);

        $payments = $paymentQuery
            ->when($cashierId, fn ($query) => $query->where('recorded_by', $cashierId))
            ->when($shiftId, fn ($query) => $query->where('register_shift_id', $shiftId)
                ->whereIn('register_shift_id', $visibleShifts->pluck('id')));
        $collected = (int) (clone $payments)->sum('amount');
        $matchingOrders = (clone $payments)->select('order_id');

        $orders = $orderQuery->with(['items', 'table', 'kiosk', 'payments'])
            ->where(function ($query) use ($cashierId, $shiftId, $matchingOrders, $start, $end) {
                $query->whereIn('orders.id', $matchingOrders);
                if (! $cashierId && ! $shiftId) {
                    $query->orWhere(fn ($created) => $created->where('created_at', '>=', $start)->where('created_at', '<', $end));
                }
            })->latest()->orderByDesc('id')->limit(100)->get();
        $displayPayments = (clone $payments)->whereIn('order_id', $orders->modelKeys())->get()->groupBy('order_id');

        $nameIds = $orders->pluck('user_id')
            ->merge($displayPayments->flatten(1)->pluck('recorded_by'))
            ->merge($orders->flatMap(fn (Order $order) => collect($order->adjustment_approvals ?? [])->pluck('manager_id')))
            ->merge($visibleShifts->pluck('opened_by'))->merge($visibleShifts->pluck('closed_by'))->filter()->unique();
        $names = User::query()->whereIn('id', $nameIds)->pluck('name', 'id');

        return compact('orders', 'cashiers', 'shifts', 'visibleShifts', 'collected', 'displayPayments', 'names');
    }
}
