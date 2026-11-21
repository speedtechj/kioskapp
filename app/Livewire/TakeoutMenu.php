<?php

namespace App\Livewire;

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
    use \App\Livewire\Concerns\ChecksBusinessAvailability;

    #[Locked]
    public string $token;

    #[Locked]
    public string $requestKey;

    public array $cart = [];

    public string $category = 'All';

    public string $search = '';

    public bool $reviewOpen = false;

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

    public function add(int $id): void
    {
        $kiosk = $this->kiosk();
        if (! RegisterShifts::active($kiosk->id)) {
            return;
        }
        $product = $kiosk->visibleProducts()->where('business_id', $kiosk->business_id)->where('active', true)->findOrFail($id);
        $quantity = ($this->cart[$id] ?? 0) + 1;
        if ($quantity <= $product->pivot->stock && $quantity <= 99) {
            $this->cart[$id] = $quantity;
        } else {
            $this->addError('cart', 'This item has reached its available quantity.');
        }
    }

    public function decrease(int $id): void
    {
        if (isset($this->cart[$id])) {
            if (--$this->cart[$id] <= 0) {
                unset($this->cart[$id]);
            }
        }
    }

    public function submit(): void
    {
        $this->resetValidation('ordering');
        $this->validate(['name' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:500']);
        $kiosk = $this->kiosk();
        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, null, null, $this->name, $this->notes);
        Cookie::queue($this->trackingCookie($kiosk), $order->slug, 1440, '/', null, request()->isSecure(), true, false, 'lax');
        $this->redirectRoute('tracking', ['token' => $order->slug]);
    }

    public function updatedReviewOpen(): void
    {
        $this->js('window.scrollTo({top: 0, behavior: "instant"})');
    }

    public string $menuLanguage = "";

    public function render()
    {
        $kiosk = $this->kiosk();
        $all = $kiosk->visibleProducts()->where('business_id', $kiosk->business_id)->where('active', true)->get();
        $languages = array_intersect_key(config('gather-ai.languages'), $all->flatMap(fn ($p) => array_keys($p->published_translations ?? []))->flip()->all());
        $language = isset($languages[$this->menuLanguage]) ? $this->menuLanguage : '';
        $products = $all->filter(fn ($p) => ($this->category === 'All' || $p->category === $this->category) && str_contains(mb_strtolower($p->menuText('name', $language)), mb_strtolower($this->search)));
        $categories = $all->pluck('category')->unique();
        $cartProducts = $all->whereIn('id', array_keys($this->cart));
        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0));
        $fee = (int) round($subtotal * $kiosk->service_charge_bps / 10000);

        $orderingOpen = RegisterShifts::active($kiosk->id) !== null;

        $activeOrder = $this->reviewOpen ? null : $this->activeTakeoutOrder($kiosk);

        return view('livewire.takeout-menu', compact('activeOrder', 'orderingOpen', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Takeout at '.$kiosk->business->name, 'manifestUrl' => route('takeout.manifest', [$kiosk->business, $kiosk])]);
    }
}
