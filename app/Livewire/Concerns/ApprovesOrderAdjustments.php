<?php

namespace App\Livewire\Concerns;

use App\Models\Kiosk;
use App\Services\Access;
use App\Services\StaffPins;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait ApprovesOrderAdjustments
{
    #[Locked]
    public array $pendingAdjustment = [];

    public function confirmAdjustment(int $managerId, string $pin): void
    {
        Access::authorize($this->businessId, 'orders.pay', $this->kioskId);
        if (! $this->pendingAdjustment) {
            throw ValidationException::withMessages(['adjustment' => 'Review the adjustment before approving it.']);
        }
        $kiosk = Kiosk::where('business_id', $this->businessId)->findOrFail($this->kioskId);
        $approval = StaffPins::approval($kiosk, $managerId, $pin);
        $this->commitAdjustment($this->pendingAdjustment, $approval);
        $this->reset('pendingAdjustment');
        $this->resetErrorBag();
        $this->dispatch('adjustment-approved');
    }

    private function reviewAdjustment(array $proposal): void
    {
        $this->resetErrorBag();
        $this->pendingAdjustment = $proposal;
        $this->dispatch('adjustment-review');
    }
}
