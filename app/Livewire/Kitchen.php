<?php

namespace App\Livewire;

use App\Models\Business;
use App\Models\Kiosk;
use App\Models\Order;
use App\Services\Access;
use App\Services\BusinessAudit;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Kitchen extends Component
{
    use \App\Livewire\Concerns\ChecksBusinessAvailability;

    #[Locked]
    public int $businessId;

    #[Locked]
    public int $kioskId;

    public ?string $ticketView = null;

    public function showTickets(string $view): void
    {
        Access::authorize($this->businessId, 'kitchen.use', $this->kioskId);
        abort_unless(in_array($view, ['tables', 'takeout'], true), 422);
        $this->ticketView = $view;
        $this->resetValidation('pickup');
    }

    public function mount(Business $business, Kiosk $kiosk): void
    {
        abort_unless($kiosk->business_id === $business->id, 404);
        $this->businessId = $business->id;
        $this->kioskId = $kiosk->id;
        Access::authorize($business->id, 'kitchen.use', $kiosk->id);
    }

    public function advance(int $id, ?string $expectedStatus = null): void
    {
        Access::authorize($this->businessId, 'orders.update', $this->kioskId);
        $order = Order::where('business_id', $this->businessId)->where('kiosk_id', $this->kioskId)->findOrFail($id);
        if ($expectedStatus !== null && $order->status !== $expectedStatus) {
            return;
        }

        $next = ['received' => 'preparing', 'preparing' => 'ready', 'ready' => 'completed'][$order->status] ?? null;
        if ($next) {
            DB::transaction(function () use ($order, $next) {
                \App\Services\BusinessAvailability::enforce($order->business_id, lock: true);
                $transition = Order::whereKey($order->id)->where('status', $order->status);
                $requiresPayment = $next === 'completed' && $order->dining_table_id === null;
                $changed = $transition->when($requiresPayment, fn ($query) => $query->where('payment_status', 'paid'))
                    ->update(['status' => $next]);

                if ($requiresPayment && ! $changed) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'pickup' => 'Refresh the order and collect the full balance before marking it picked up.',
                    ]);
                }

                if ($changed) {
                    BusinessAudit::record($order->business_id, 'Order marked '.$next, BusinessAudit::orderLabel($order->id), $order->kiosk_id);
                }
            });
        }
    }

    public function render()
    {
        Access::authorize($this->businessId, 'kitchen.use', $this->kioskId);
        $business = Business::findOrFail($this->businessId);
        $kiosk = Kiosk::findOrFail($this->kioskId);
        $orders = $kiosk->orders()->with('items', 'table')->whereIn('status', ['received', 'preparing', 'ready'])->oldest()->get();

        $takeoutOrders = $orders->whereNull('dining_table_id');
        $tableGroups = $orders->whereNotNull('dining_table_id')->groupBy('dining_table_id');
        $view = in_array($this->ticketView, ['tables', 'takeout'], true)
            ? $this->ticketView
            : ($tableGroups->isEmpty() && $takeoutOrders->isNotEmpty() ? 'takeout' : 'tables');
        $canAdvance = Access::allows(auth()->user(), $business->id, 'orders.update');

        return view('livewire.kitchen', compact('business', 'kiosk', 'tableGroups', 'takeoutOrders', 'view', 'canAdvance'))->layout('layouts.app',['title' => 'Kitchen display']);
    }
}
