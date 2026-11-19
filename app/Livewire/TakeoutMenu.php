<?php

namespace App\Livewire;

use App\Models\Business;
use App\Models\Kiosk;
use App\Services\PlaceOrder;
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
        $this->kiosk();
    }

    protected function kiosk(): Kiosk
    {
        return Kiosk::with('business')->where('takeout_token', $this->token)->where('active', true)->firstOrFail();
    }

    public function add(int $id): void
    {
        $kiosk = $this->kiosk();
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
        $this->validate(['name' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:500']);
        $order = app(PlaceOrder::class)->handle($this->kiosk(), $this->cart, $this->requestKey, null, null, $this->name, $this->notes);
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

        return view('livewire.takeout-menu', compact('kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Takeout at '.$kiosk->business->name, 'manifestUrl' => route('takeout.manifest', [$kiosk->business, $kiosk])]);
    }
}
