<?php

namespace App\Livewire;

use App\Models\Business;
use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Services\PlaceOrder;
use App\Services\RegisterShifts;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CustomerMenu extends Component
{
    use \App\Livewire\Concerns\ChecksBusinessAvailability;

    #[Locked]
    public string $token;

    #[Locked]
    public string $requestKey;

    public array $cart = [];

    public string $category = 'All';

    public string $search = '';

    public bool $basketOpen = false;

    public string $name = '';

    public string $notes = '';

    public function mount(Business $business, Kiosk $kiosk, DiningTable $table): void
    {
        $this->token = $table->token;
        $this->requestKey = (string) Str::uuid();
        $this->table();
    }

    public function table(): DiningTable
    {
        $table = DiningTable::with('kiosk.business')->where('token', $this->token)->where('active', true)->firstOrFail();
        abort_unless($table->kiosk->active, 404);

        return $table;
    }

    public function add(int $id): void
    {
        $table = $this->table();
        if (! RegisterShifts::active($table->kiosk_id)) {
            return;
        }
        $p = $table->kiosk->visibleProducts()->where('business_id', $table->kiosk->business_id)->where('active', true)->findOrFail($id);
        $qty = ($this->cart[$id] ?? 0) + 1;
        if ($qty <= $p->pivot->stock && $qty <= 99) {
            $this->cart[$id] = $qty;
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
        $table = $this->table();
        $order = app(PlaceOrder::class)->handle($table->kiosk, $this->cart, $this->requestKey, $table, null, $this->name, $this->notes);
        $this->redirectRoute('tracking', ['token' => $order->slug]);
    }

    public string $menuLanguage = "";

    public function render()
    {
        $table = $this->table();
        $all = $table->kiosk->visibleProducts()->where('business_id', $table->kiosk->business_id)->where('active', true)->get();
        $languages = array_intersect_key(config('gather-ai.languages'), $all->flatMap(fn ($p) => array_keys($p->published_translations ?? []))->flip()->all());
        $language = isset($languages[$this->menuLanguage]) ? $this->menuLanguage : '';
        $products = $all->filter(fn ($p) => ($this->category === 'All' || $p->category === $this->category) && str_contains(mb_strtolower($p->menuText('name', $language)), mb_strtolower($this->search)));
        $categories = $all->pluck('category')->unique();
        $cartProducts = $all->whereIn('id', array_keys($this->cart));
        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0));
        $fee = (int) round($subtotal * $table->kiosk->service_charge_bps / 10000);

        $orderingOpen = RegisterShifts::active($table->kiosk_id) !== null;

        return view('livewire.customer-menu', compact('orderingOpen', 'table', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Order at '.$table->kiosk->business->name, 'manifestUrl' => route('menu.manifest', [$table->kiosk->business, $table->kiosk, $table])]);
    }
}
