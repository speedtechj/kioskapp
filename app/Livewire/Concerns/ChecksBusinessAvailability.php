<?php

namespace App\Livewire\Concerns;

use App\Livewire\CustomerMenu;
use App\Livewire\CustomerOrder;
use App\Livewire\Kitchen;
use App\Livewire\PaymentDesk;
use App\Livewire\TakeoutMenu;
use App\Livewire\Terminal;
use App\Services\BusinessAvailability;

trait ChecksBusinessAvailability
{
    public function hydrateChecksBusinessAvailability(): void
    {
        $surface = match (true) {
            $this instanceof CustomerMenu => 'menu',
            $this instanceof TakeoutMenu => 'takeout',
            $this instanceof CustomerOrder => 'tracking',
            $this instanceof Terminal => 'pos',
            $this instanceof Kitchen => 'kitchen',
            $this instanceof PaymentDesk => 'payments',
            default => 'workspace',
        };
        $id = match ($surface) {
            'menu', 'takeout', 'tracking' => BusinessAvailability::customerBusinessId($surface, $this->token),
            default => $this->businessId,
        };

        abort_if($id === null, 404);
        BusinessAvailability::enforce($id, $surface);
    }
}
