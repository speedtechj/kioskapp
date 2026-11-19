<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesSummary
{
    public function report(int $businessId, array $kioskIds, Carbon $start, Carbon $end): array
    {
        $orders = DB::table('orders')->where('business_id', $businessId)->whereIn('kiosk_id', $kioskIds);
        $payments = DB::table('order_payments')->joinSub(
            (clone $orders)->select('id', 'kiosk_id'),
            'report_orders',
            'report_orders.id',
            '=',
            'order_payments.order_id',
        );
        $between = [$start->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()];
        $sales = (clone $orders)->whereBetween('created_at', $between)
            ->where(function ($query) {
                $query->where('payment_status', 'paid')->orWhere(function ($cancelled) {
                    $cancelled->whereIn('payment_status', ['refund_pending', 'refunded'])
                        ->whereRaw('orders.total <= (SELECT COALESCE(SUM(amount), 0) FROM order_payments WHERE order_id = orders.id)');
                });
            })
            ->selectRaw('DATE(created_at) AS day, kiosk_id, COUNT(*) AS paid_orders')
            ->selectRaw('SUM(total + COALESCE(discount_amount, 0) + COALESCE(complimentary_amount, 0)) AS gross')
            ->selectRaw('SUM(COALESCE(discount_amount, 0) + COALESCE(complimentary_amount, 0)) AS discounts')
            ->groupByRaw('DATE(created_at), kiosk_id')->get();

        $refunds = (clone $payments)->whereBetween('refunded_at', $between)
            ->selectRaw('DATE(refunded_at) AS day, report_orders.kiosk_id AS kiosk_id, SUM(amount) AS refunds')
            ->groupByRaw('DATE(refunded_at), report_orders.kiosk_id')->get();

        $collections = $payments->whereBetween('confirmed_at', $between)
            ->selectRaw('method, SUM(amount) AS amount')->groupBy('method')->pluck('amount', 'method');
        $legacy = (clone $orders)->whereBetween('created_at', $between)->where('payment_status', 'paid')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('order_payments')->whereColumn('order_payments.order_id', 'orders.id');
            })
            ->selectRaw("COALESCE(payment_method, 'legacy') AS method, SUM(total) AS amount")
            ->groupByRaw("COALESCE(payment_method, 'legacy')")->pluck('amount', 'method');
        foreach ($legacy as $method => $amount) {
            $collections[$method] = ($collections[$method] ?? 0) + $amount;
        }

        $empty = ['gross' => 0, 'discounts' => 0, 'refunds' => 0, 'net' => 0, 'paid_orders' => 0];
        $days = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $days[$date->toDateString()] = $empty;
        }
        $kiosks = array_fill_keys($kioskIds, $empty);
        foreach ($sales->concat($refunds) as $row) {
            $gross = (int) ($row->gross ?? 0);
            $discounts = (int) ($row->discounts ?? 0);
            $refund = (int) ($row->refunds ?? 0);
            $values = [
                'gross' => $gross,
                'discounts' => $discounts,
                'refunds' => $refund,
                'net' => $gross - $discounts - $refund,
                'paid_orders' => (int) ($row->paid_orders ?? 0),
            ];
            foreach ($values as $metric => $value) {
                $days[$row->day][$metric] += $value;
                $kiosks[$row->kiosk_id][$metric] += $value;
            }
        }

        $totals = $empty;
        foreach ($kiosks as $kiosk) {
            foreach ($kiosk as $metric => $value) {
                $totals[$metric] += $value;
            }
        }

        return ['totals' => $totals, 'days' => $days, 'kiosks' => $kiosks, 'methods' => $collections->sortDesc()];
    }
}
