<?php

namespace App\Livewire\Concerns;

use App\Models\Kiosk;
use App\Services\Access;
use App\Services\Money;
use App\Services\RegisterShifts;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait ManagesRegisterShift
{
    public string $startingCash = '0.00';

    public array $cashCounts = [];

    #[Locked]
    public ?int $reviewedShiftId = null;

    #[Locked]
    public array $drawerTotals = [];

    public function openRegisterShift(): void
    {
        Access::authorize($this->businessId, 'orders.pay', $this->kioskId);
        $this->validate(['startingCash' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/D']]);
        RegisterShifts::open($this->shiftKiosk(), Money::cents($this->startingCash));
        $this->reset('startingCash');
        $this->resetErrorBag('shift');
        $this->dispatch('register-shift-saved');
    }

    public function reviewRegisterShift(): void
    {
        Access::authorize($this->businessId, 'orders.pay', $this->kioskId);
        $shift = RegisterShifts::requireOpen($this->kioskId);
        $this->reviewedShiftId = $shift->id;
        $this->drawerTotals = ['opening' => (int) $shift->opening_cash] + RegisterShifts::totals($shift);
        $this->reset('cashCounts');
        $this->resetErrorBag();
        $this->dispatch('register-close-reviewed');
    }

    public function closeRegisterShift(): void
    {
        Access::authorize($this->businessId, 'orders.pay', $this->kioskId);
        $this->validate([
            'cashCounts' => ['array:'.implode(',', array_keys(RegisterShifts::DENOMINATIONS))],
            'cashCounts.*' => 'nullable|integer|min:0|max:99999',
        ]);
        if ($this->reviewedShiftId === null) {
            throw ValidationException::withMessages(['shift' => 'Review the current shift before closing it.']);
        }

        $counts = array_map('intval', $this->cashCounts);
        try {
            RegisterShifts::close($this->shiftKiosk(), $this->reviewedShiftId, $counts, $this->drawerTotals['expected']);
        } catch (ValidationException $exception) {
            $shift = RegisterShifts::active($this->kioskId);
            if ($shift?->id === $this->reviewedShiftId) {
                $this->drawerTotals = ['opening' => (int) $shift->opening_cash] + RegisterShifts::totals($shift);
            }

            throw $exception;
        }

        $this->reset('reviewedShiftId', 'drawerTotals', 'cashCounts');
        $this->resetErrorBag('shift');
        $this->dispatch('register-shift-saved');
        session()->flash('shift_notice', 'Shift closed. Open a new shift to resume selling.');
    }

    private function shiftKiosk(): Kiosk
    {
        return Kiosk::where('business_id', $this->businessId)->findOrFail($this->kioskId);
    }
}
