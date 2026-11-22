<?php

namespace App\Livewire;

use App\Livewire\Concerns\ChecksBusinessAvailability;
use App\Livewire\Concerns\SelectsToppings;
use App\Models\Business;
use App\Models\Kiosk;
use App\Models\Order;
use App\Services\PlaceOrder;
use App\Services\RegisterShifts;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class TakeoutMenu extends Component
{
    use ChecksBusinessAvailability;
    use SelectsToppings;

    #[Locked]
    public string $token;

    #[Locked]
    public string $requestKey;

    public array $cart = [];

    public string $category = 'All';

    public string $search = '';

    public bool $reviewOpen = false;

    #[Locked]
    public ?int $detailId = null;

    #[Locked]
    public int $detailQuantity = 1;

    #[Locked]
    public bool $checkoutOpen = false;

    public string $name = '';

    public string $notes = '';

    public function mount(Business $business, Kiosk $kiosk): void
    {
        $this->token = $kiosk->takeout_token;
        $this->requestKey = (string) Str::uuid();
        $kiosk = $this->kiosk();
        $order = $this->activeTakeoutOrder($kiosk);
        if ($order && ! request()->boolean('browse')) {
            $this->redirectRoute('tracking', ['token' => $order->slug]);
        }
    }

    protected function kiosk(): Kiosk
    {
        return Kiosk::with('business')->where('takeout_token', $this->token)->where('active', true)->firstOrFail();
    }

    private function trackingCookie(Kiosk $kiosk): string
    {
        return 'takeout_order_'.$kiosk->id;
    }

    private function activeTakeoutOrder(Kiosk $kiosk): ?Order
    {
        $cookie = $this->trackingCookie($kiosk);
        $slug = request()->cookie($cookie);
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $order = Order::query()
            ->where('slug', $slug)
            ->where('business_id', $kiosk->business_id)
            ->where('kiosk_id', $kiosk->id)
            ->whereNull('dining_table_id')
            ->where('source', 'qr')
            ->whereIn('status', ['received', 'preparing', 'ready'])
            ->first();

        if (! $order) {
            Cookie::expire($cookie, '/');
        }

        return $order;
    }

    protected function toppingBusinessId(): int
    {
        return $this->kiosk()->business_id;
    }

    public function showItem(int $id): void
    {
        $kiosk = $this->kiosk();
        $kiosk->visibleProducts()->where('business_id', $kiosk->business_id)->where('active', true)->findOrFail($id);
        $this->detailId = $id;
        $this->detailQuantity = 1;
        $this->resetValidation('cart');
        $this->scrollToTop();
    }

    public function closeItem(): void
    {
        $this->detailId = null;
        $this->scrollToTop();
    }

    public function changeDetailQuantity(bool $increase): void
    {
        if ($this->detailId !== null) {
            $this->detailQuantity = max(1, min(99, $this->detailQuantity + ($increase ? 1 : -1)));
        }
    }

    public function addDetail(): void
    {
        if ($this->detailId !== null && $this->add($this->detailId, $this->detailQuantity)) {
            $this->closeItem();
            $this->dispatch('takeout-item-added');
        }
    }

    public function showCheckout(): void
    {
        if ($this->cart) {
            $this->reviewOpen = true;
            $this->checkoutOpen = true;
            $this->scrollToTop();
        }
    }

    public function closeCheckout(): void
    {
        $this->checkoutOpen = false;
        $this->scrollToTop();
    }

    public function backToMenu(): void
    {
        $this->reviewOpen = false;
        $this->closeCheckout();
    }

    public function add(int $id, int $count = 1): bool
    {
        $kiosk = $this->kiosk();
        if (! RegisterShifts::active($kiosk->id)) {
            return false;
        }
        abort_unless($count >= 1 && $count <= 99, 422);
        $product = $kiosk->visibleProducts()->where('business_id', $kiosk->business_id)->where('active', true)->findOrFail($id);
        $quantity = ($this->cart[$id] ?? 0) + $count;
        if ($quantity <= $product->pivot->stock && $quantity <= 99) {
            $this->cart[$id] = $quantity;
            $this->resetValidation('cart');

            return true;
        }

        $this->addError('cart', 'This item has reached its available quantity.');

        return false;
    }

    public function decrease(int $id): void
    {
        if (isset($this->cart[$id])) {
            if (--$this->cart[$id] <= 0) {
                unset($this->cart[$id]);
                if (! $this->cart) {
                    $this->toppingQuantities = [];
                }
            }
        }
    }

    public function submit(): void
    {
        $this->resetValidation('ordering');
        $this->validate(['name' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:500']);
        $kiosk = $this->kiosk();
        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, null, null, $this->name, $this->notes, toppings: $this->toppingQuantities);
        Cookie::queue($this->trackingCookie($kiosk), $order->slug, 1440, '/', null, request()->isSecure(), true, false, 'lax');
        $this->redirectRoute('tracking', ['token' => $order->slug]);
    }

    public function updatedReviewOpen(): void
    {
        $this->checkoutOpen = false;
        $this->scrollToTop();
    }

    private function scrollToTop(): void
    {
        $this->js('window.scrollTo({top: 0, behavior: "instant"})');
    }

    public string $menuLanguage = '';

    public function render()
    {
        $kiosk = $this->kiosk();
        $all = $kiosk->visibleProducts()->where('business_id', $kiosk->business_id)->where('active', true)->get();
        $languages = array_intersect_key(config('gather-ai.languages'), $all->flatMap(fn ($p) => array_keys($p->published_translations ?? []))->flip()->all());
        $language = isset($languages[$this->menuLanguage]) ? $this->menuLanguage : '';
        $products = $all->filter(fn ($p) => ($this->category === 'All' || $p->category === $this->category) && str_contains(mb_strtolower($p->menuText('name', $language)), mb_strtolower($this->search)));
        $categories = $all->pluck('category')->unique();
        $cartProducts = $all->whereIn('id', array_keys($this->cart));
        $toppings = $this->cart ? $this->toppingOptions($kiosk->business_id) : collect();
        $toppingSubtotal = $this->toppingSubtotal($toppings);
        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0)) + $toppingSubtotal;
        $fee = (int) round($subtotal * $kiosk->service_charge_bps / 10000);

        $orderingOpen = RegisterShifts::active($kiosk->id) !== null;

        $activeOrder = $this->reviewOpen ? null : $this->activeTakeoutOrder($kiosk);

        $detail = $all->firstWhere('id', $this->detailId);

        return view('livewire.takeout-menu', compact('detail', 'toppings', 'toppingSubtotal', 'activeOrder', 'orderingOpen', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Takeout at '.$kiosk->business->name, 'manifestUrl' => route('takeout.manifest', [$kiosk->business, $kiosk])]);
    }
}
