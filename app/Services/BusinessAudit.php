<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessAiSetting;
use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BusinessAudit
{
    private const EDITABLE = [
        Business::class => ['Business', ['name', 'slug', 'logo']],
        BusinessAiSetting::class => ['AI settings', ['enabled', 'monthly_limit_cents', 'pause_at_limit', 'helpers']],
        Kiosk::class => ['Kiosk', ['name', 'slug', 'active', 'archived_at', 'service_charge_bps', 'app_icon_key', 'cashier_icon_key', 'cashier_favicon_key', 'app_label']],
        DiningTable::class => ['Table', ['name', 'slug', 'active']],
        Product::class => ['Menu item', ['name', 'category', 'price', 'description', 'active', 'image', 'visible_kiosk_ids', 'published_translations']],
    ];

    public static function canView(User $user, int $businessId): bool
    {
        return ! session()->has('pos_pin_scope')
            && in_array(Access::membership($user, $businessId)?->pivot->role, ['owner', 'manager'], true);
    }

    public static function record(
        int $businessId,
        string $action,
        ?string $subject = null,
        ?int $kioskId = null,
        array $details = [],
        ?User $actor = null,
    ): void {
        $actor ??= auth()->user();
        if (! $actor) {
            return;
        }
        $role = Access::membership($actor, $businessId)?->pivot->role;
        if (! $role && ! $actor->is_super_admin) {
            return;
        }

        DB::table('business_audit_events')->insert([
            'business_id' => $businessId,
            'kiosk_id' => $kioskId,
            'kiosk_name' => $kioskId ? Kiosk::where('business_id', $businessId)->whereKey($kioskId)->value('name') : null,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'role' => $role ?? 'super_admin',
            'action' => $action,
            'subject' => $subject,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'occurred_at' => now(),
        ]);
    }

    public static function session(string $action, User $user, ?array $scope = null): void
    {
        if ($scope) {
            self::record($scope['business'], $action, kioskId: $scope['kiosk'], actor: $user);

            return;
        }

        foreach ($user->businesses()->pluck('businesses.id') as $businessId) {
            self::record($businessId, $action, actor: $user);
        }
    }

    public static function listen(): void
    {
        foreach (array_keys(self::EDITABLE) as $model) {
            if (! in_array($model, [Business::class, BusinessAiSetting::class], true)) {
                $model::created(fn (Model $record) => self::edited($record, 'created'));
                $model::deleted(fn (Model $record) => self::edited($record, 'deleted'));
            }
            $model::updated(fn (Model $record) => self::edited($record, 'updated'));
        }

        Order::created(function (Order $order): void {
            if ($order->source === 'pos') {
                self::record($order->business_id, 'Order placed', self::orderLabel($order->id), $order->kiosk_id, ['Total' => self::money($order->total)]);
            }
        });
        Order::updated(function (Order $order): void {
            if (! $order->wasChanged('adjustment_approvals')) {
                return;
            }
            $previous = count($order->getOriginal('adjustment_approvals') ?? []);
            foreach (array_slice($order->adjustment_approvals ?? [], $previous) as $approval) {
                $manager = User::find($approval['manager_id'] ?? null);
                if ($manager) {
                    $label = $approval['action'] ?? 'Order adjustment';
                    $action = match (true) {
                        str_contains(strtolower($label), 'discount') => 'Discount approved',
                        str_contains(strtolower($label), 'complimentary') => 'Complimentary adjustment approved',
                        default => 'Adjustment approved',
                    };
                    self::record($order->business_id, $action, self::orderLabel($order->id), $order->kiosk_id, [
                        'Adjustment' => $label,
                        'Requested by' => auth()->user()?->name,
                        'Approved at' => $approval['approved_at'] ?? null,
                        'Before' => self::money($approval['before'] ?? 0),
                        'After' => self::money($approval['after'] ?? 0),
                    ], $manager);
                }
            }
        });

        OrderPayment::created(function (OrderPayment $payment): void {
            if ($payment->register_shift_id && ($order = $payment->order)) {
                self::payment($order, $payment, 'Payment collected');
            }
        });
        OrderPayment::updated(function (OrderPayment $payment): void {
            if ($payment->wasChanged('refunded_at') && $payment->refunded_at && ($order = $payment->order)) {
                self::payment($order, $payment, 'Refund confirmed');
            }
        });

        RegisterShift::created(fn (RegisterShift $shift) => self::shift($shift, 'Shift opened', ['Opening cash' => self::money($shift->opening_cash)]));
        RegisterShift::updated(function (RegisterShift $shift): void {
            if ($shift->wasChanged('closed_at') && $shift->closed_at) {
                self::shift($shift, 'Shift closed', [
                    'Expected cash' => self::money($shift->expected_cash),
                    'Counted cash' => self::money($shift->counted_cash),
                ]);
            }
        });
    }

    public static function orderLabel(int $id): string
    {
        return 'Order #'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private static function edited(Model $record, string $operation): void
    {
        if (! auth()->check()) {
            return;
        }
        [$label, $fields] = self::EDITABLE[$record::class];
        $values = Arr::only($operation === 'updated' ? $record->getChanges() : $record->getAttributes(), $fields);
        if ($values === []) {
            return;
        }
        $details = $operation === 'updated'
            ? ['Before' => Arr::only($record->getRawOriginal(), array_keys($values)), 'After' => $values]
            : $values;
        $businessId = match (true) {
            $record instanceof Business => $record->id,
            $record instanceof DiningTable => $record->kiosk?->business_id,
            default => $record->business_id,
        };
        if ($businessId) {
            self::record($businessId, "$label $operation", $record->name, $record instanceof Kiosk ? $record->id : $record->kiosk_id, $details);
        }
    }

    private static function payment(Order $order, OrderPayment $payment, string $action): void
    {
        self::record($order->business_id, $action, self::orderLabel($order->id), $order->kiosk_id, [
            'Method' => $payment->method,
            'Amount' => self::money($payment->amount),
            'Payment ID' => $payment->id,
        ]);
    }

    private static function shift(RegisterShift $shift, string $action, array $details): void
    {
        if ($businessId = Kiosk::whereKey($shift->kiosk_id)->value('business_id')) {
            self::record($businessId, $action, 'Shift #'.$shift->id, $shift->kiosk_id, $details);
        }
    }

    private static function money(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
