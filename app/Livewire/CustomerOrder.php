<?php

namespace App\Livewire;

use App\Models\Order;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class CustomerOrder extends Component
{
    use \App\Livewire\Concerns\ChecksBusinessAvailability;

    #[Locked]
    public string $token;

    #[Url(as: 'view')]
    public string $screen = 'status';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function updatedScreen(): void
    {
        $this->js('window.scrollTo({top: 0, behavior: "instant"})');
    }

    public function cancel(): void
    {
        app(\App\Services\CancelOrder::class)->byCustomer($this->token);
    }

    public function render()
    {
        abort_unless(in_array($this->screen, ['status', 'payment'], true), 404);
        $order = Order::with('items', 'table', 'kiosk.business', 'payments')->where('slug', $this->token)->firstOrFail();
        $paid = $order->paidAmount();
        $remaining = $order->remainingAmount();

        return view('livewire.customer-order', compact('order', 'paid', 'remaining'))->layout('layouts.app', ['title' => 'Order #'.str_pad($order->id, 4, '0', STR_PAD_LEFT)]);
    }
}
