<?php

namespace App\Livewire\Concerns;

use App\Services\OrderToppings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

trait SelectsToppings
{
    #[Locked]
    public array $toppingQuantities = [];

    abstract protected function toppingBusinessId(): int;

    public function changeTopping(int $id, bool $increase): void
    {
        $businessId = $this->toppingBusinessId();
        $current = $this->toppingQuantities[$id] ?? 0;

        if ($increase) {
            if (! $this->cart || $current >= 99 || ($current === 0 && count($this->toppingQuantities) >= 50)
                || ! OrderToppings::catalog($businessId)->where('id', $id)->where('active', true)->exists()) {
                return;
            }
            $this->toppingQuantities[$id] = $current + 1;
        } elseif ($current > 1) {
            $this->toppingQuantities[$id] = $current - 1;
        } else {
            unset($this->toppingQuantities[$id]);
        }

        $this->resetValidation('toppings');
    }

    protected function toppingOptions(int $businessId): Collection
    {
        return OrderToppings::catalog($businessId)->where(function ($query) {
            $query->where('active', true)->orWhereIn('id', array_keys($this->toppingQuantities));
        })->orderBy('name')->get();
    }

    protected function toppingSubtotal(Collection $toppings): int
    {
        return $toppings->where('active', true)->sum(fn ($topping) => $topping->price * ($this->toppingQuantities[$topping->id] ?? 0));
    }
}
