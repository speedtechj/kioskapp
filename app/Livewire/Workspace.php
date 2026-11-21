<?php

namespace App\Livewire;

use App\Models\Business;
use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Access;
use App\Services\BusinessAudit;
use App\Services\UrlSlugs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Workspace extends Component
{
    use \App\Livewire\Concerns\ChecksBusinessAvailability;

    // gather-menu-photo-upload:v1
    use WithFileUploads;

    public $photo = null;
    #[Locked]
    public int $businessId;

    #[Url(as: 'tab')]
    public string $section = 'overview';

    public int $selectedKiosk = 0;

    public string $orderDate = '';

    public int $orderCashier = 0;

    public int $orderShift = 0;

    #[Locked]
    public int $appliedOrderCashier = 0;

    #[Locked]
    public int $appliedOrderShift = 0;

    #[Locked]
    public string $appliedOrderDate = '';

    public string $search = '';

    public bool $modal = false;

    public string $formType = '';

    public array $form = [];

    public ?int $editingId = null;

    private const SECTIONS = ['overview' => 'dashboard.view', 'orders' => 'orders.view', 'menu' => 'menu.manage', 'toppings' => 'menu.manage', 'inventory' => 'inventory.manage', 'kiosks' => 'kiosks.manage', 'staff' => 'staff.manage', 'reports' => 'reports.view'];

    public function mount(Business $business): void
    {
        $this->businessId = $business->id;
        $this->orderDate = $this->appliedOrderDate = today()->toDateString();
        $this->authorizeSection();
    }

    public ?int $toppingId = null;

    public bool $toppingEditorOpen = false;

    public array $toppingForm = [];

    public function editTopping(?int $id = null): void
    {
        Access::authorize($this->businessId, 'menu.manage');
        $topping = $id === null ? null : \App\Services\OrderToppings::catalog($this->businessId)->where('id', $id)->first();
        abort_if($id !== null && ! $topping, 404);

        $this->toppingId = $id;
        $this->toppingForm = [
            'name' => $topping->name ?? '',
            'price' => $topping ? \App\Services\Money::input($topping->price) : '',
            'active' => $topping ? (bool) $topping->active : true,
        ];
        $this->resetValidation();
        $this->toppingEditorOpen = true;
    }

    public function saveTopping(): void
    {
        Access::authorize($this->businessId, 'menu.manage');
        $form = $this->validate([
            'toppingForm.name' => 'required|string|max:100',
            'toppingForm.price' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/D', 'numeric', 'min:0.01'],
            'toppingForm.active' => 'required|boolean',
        ])['toppingForm'];
        $data = [
            'name' => trim($form['name']),
            'price' => \App\Services\Money::cents((string) $form['price']),
            'active' => $form['active'],
        ];

        $catalog = \App\Services\OrderToppings::catalog($this->businessId);
        if ($this->toppingId === null) {
            $catalog->insert($data + ['business_id' => $this->businessId]);
        } else {
            $row = $catalog->where('id', $this->toppingId);
            abort_unless($row->exists(), 404);
            $row->update($data);
        }

        $this->toppingEditorOpen = false;
    }

    public function navigate(string $section): void
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        Access::authorize($this->businessId, self::SECTIONS[$section]);
        $this->section = $section;
        $this->search = '';
        $this->modal = false;
    }

    public function filterOrders(): void
    {
        Access::authorize($this->businessId, 'orders.view');
        $this->validate(['orderDate' => 'required|date_format:Y-m-d']);
        $this->appliedOrderDate = $this->orderDate;
        $this->appliedOrderCashier = $this->orderCashier;
        $this->appliedOrderShift = $this->orderShift;
    }

    public function showTodaysOrders(): void
    {
        Access::authorize($this->businessId, 'orders.view');
        $this->resetValidation('orderDate');
        $this->orderDate = $this->appliedOrderDate = today()->toDateString();
        $this->reset('orderCashier', 'orderShift', 'appliedOrderCashier', 'appliedOrderShift');
    }

    public function updatedSelectedKiosk(): void
    {
        if ($this->section === 'orders') {
            $this->reset('orderCashier', 'orderShift', 'appliedOrderCashier', 'appliedOrderShift');
        }
    }

    public function updatedOrderDate(): void
    {
        $this->reset('orderCashier', 'orderShift');
    }

    private function authorizeSection(): void
    {
        abort_unless(isset(self::SECTIONS[$this->section]), 404);
        Access::authorize($this->businessId, self::SECTIONS[$this->section]);
        if ($this->selectedKiosk) {
            Access::authorize($this->businessId, self::SECTIONS[$this->section], $this->selectedKiosk);
        }
    }

    public bool $allMenuKiosks = true;
    public array $menuKiosks = [];

    public string $newCategoryName = '';

    public function menuCategories(): array
    {
        Access::authorize($this->businessId, 'menu.manage');

        return DB::table('menu_categories')->where('business_id', $this->businessId)->pluck('name')
            ->merge(Product::where('business_id', $this->businessId)->whereNotNull('category')->distinct()->pluck('category'))
            ->unique(fn (string $name) => Str::lower(trim($name)))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public function addMenuCategory(): void
    {
        Access::authorize($this->businessId, 'menu.manage');
        abort_unless($this->modal && $this->formType === 'product', 403);

        $this->newCategoryName = trim($this->newCategoryName);
        $this->validate(['newCategoryName' => ['required', 'string', 'max:50', function (string $attribute, string $value, \Closure $fail): void {
            if (Str::lower($value) === 'all') {
                $fail('Choose a name other than “All”.');
            }
        }]]);

        DB::transaction(function () {
            Business::query()->lockForUpdate()->findOrFail($this->businessId);
            $normalized = Str::lower($this->newCategoryName);
            $existing = collect($this->menuCategories())->first(fn (string $name) => Str::lower(trim($name)) === $normalized);
            $nameKey = hash('sha256', $normalized);
            $inserted = DB::table('menu_categories')->insertOrIgnore([
                'business_id' => $this->businessId,
                'name_key' => $nameKey,
                'name' => $existing ?? $this->newCategoryName,
            ]);

            $this->form['category'] = DB::table('menu_categories')->where('business_id', $this->businessId)
                ->where('name_key', $nameKey)->value('name');
            if ($inserted) {
                BusinessAudit::record($this->businessId, 'Menu category created', $this->form['category']);
            }
        });
        $this->reset('newCategoryName');
        $this->dispatch('menu-category-added', name: $this->form['category']);
    }

    public function removeMenuCategory(string $category, string $replacement = ''): void
    {
        Access::authorize($this->businessId, 'menu.manage');
        abort_unless($this->modal && $this->formType === 'product', 403);

        $this->resetValidation('categoryRemoval');

        DB::transaction(function () use ($category, $replacement) {
            Business::query()->lockForUpdate()->findOrFail($this->businessId);
            $categories = $this->menuCategories();
            if (! in_array($category, $categories, true)) {
                throw ValidationException::withMessages(['categoryRemoval' => 'This category is no longer available.']);
            }

            $key = Str::lower(trim($category));
            $products = Product::where('business_id', $this->businessId);
            $productIds = (clone $products)->pluck('category', 'id')
                ->filter(fn (?string $name) => $name !== null && Str::lower(trim($name)) === $key)->keys();

            if ($productIds->isNotEmpty()) {
                if ($replacement === $category || ! in_array($replacement, $categories, true)) {
                    throw ValidationException::withMessages(['categoryRemoval' => 'Choose another category to keep these products on the menu.']);
                }

                $products->whereKey($productIds->all())->update(['category' => $replacement]);
            }

            DB::table('menu_categories')->where('business_id', $this->businessId)
                ->where('name_key', hash('sha256', $key))->delete();
        });

        if (Str::lower(trim($this->form['category'] ?? '')) === Str::lower(trim($category))) {
            $this->form['category'] = $replacement !== $category && in_array($replacement, $this->menuCategories(), true)
                ? $replacement : '';
        }

        $this->dispatch('menu-category-removed', name: $category);
    }

    public function resetValidationForCategoryRemoval(): void
    {
        $this->resetValidation('categoryRemoval');
    }

    public function menuCategoryProductCounts(): array
    {
        Access::authorize($this->businessId, 'menu.manage');

        return Product::where('business_id', $this->businessId)->whereNotNull('category')->pluck('category')
            ->countBy(fn (string $name) => Str::lower(trim($name)))->all();
    }

    public function resetMenuCategoryInput(): void
    {
        Access::authorize($this->businessId, 'menu.manage');
        $this->reset('newCategoryName');
        $this->resetValidation('newCategoryName');
    }

    public function openForm(string $type, ?int $id = null): void
    {
        $permission = ['product' => 'menu.manage', 'kiosk' => 'kiosks.manage', 'staff' => 'staff.manage', 'table' => 'kiosks.manage', 'stock' => 'inventory.manage'][$type] ?? null;
        abort_unless($permission, 404);
        Access::authorize($this->businessId, $permission);
        $this->resetValidation();
        $this->reset('photo');
        $this->formType = $type;
        $this->editingId = $id;
        $this->form = [];
        if ($type === 'product') {
            $p = $id ? Product::where('business_id', $this->businessId)->findOrFail($id) : null;
            $this->allMenuKiosks = $p?->visible_kiosk_ids === null;
            $this->menuKiosks = $p?->visible_kiosk_ids ?? [];

            $this->form = ['name' => $p?->name ?? '', 'category' => $p?->category ?? '', 'description' => $p?->description ?? '', 'price' => $p ? $p->price / 100 : '', 'image' => $p?->image ?? '/images/bowl.jpg', 'active' => $p?->active ?? true];
        }
        if ($type === 'table') {
            $t = DiningTable::whereHas('kiosk', fn ($q) => $q->where('business_id', $this->businessId))->whereIn('kiosk_id', Access::kiosks(auth()->user(), $this->businessId)->pluck('id'))->findOrFail($id);
            $this->form = ['name' => $t->name, 'slug' => $t->slug];
        }
        if ($type === 'kiosk') {
            $k = $id ? Kiosk::where('business_id', $this->businessId)->findOrFail($id) : null;
            $this->form = ['slug' => $k?->slug ?? '', 'name' => $k?->name ?? '', 'address' => $k?->address ?? '', 'active' => $k?->active ?? true, 'service_charge' => $k ? $k->service_charge_bps / 100 : 0];
        }
        if ($type === 'stock') {
            $kiosk = Kiosk::where('business_id', $this->businessId)->findOrFail($this->selectedKiosk);
            Access::authorize($this->businessId, 'inventory.manage', $kiosk->id);
            $p = $kiosk->products()->findOrFail($id);
            $this->form = ['stock' => $p->pivot->stock, 'name' => $p->name];
        }
        if ($type === 'staff') {
            $u = $id ? Business::findOrFail($this->businessId)->users()->findOrFail($id) : null;
            if ($u) {
                abort_if($u->pivot->role === 'owner' || $u->is_super_admin || $u->id === auth()->id(), 403);
            }$this->form = ['name' => $u?->name ?? '', 'email' => $u?->email ?? '', 'password' => '', 'role' => $u?->pivot->role ?? 'cashier', 'kiosk_ids' => $u ? $u->kiosks()->where('business_id', $this->businessId)->pluck('kiosks.id')->map(fn ($id) => (string) $id)->all() : [], 'permissions' => $u ? (json_decode($u->pivot->permissions ?? '[]', true) ?? []) : []];
        }
        $this->modal = true;
    }

    public function updatedPhoto(): void
    {
        Access::authorize($this->businessId, 'menu.manage');
        abort_unless($this->formType === 'product', 403);
        $this->validateOnly('photo', [
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=6000,max_height=6000',
        ]);
    }

    public function clearPhoto(): void
    {
        Access::authorize($this->businessId, 'menu.manage');
        $this->reset('photo');
        $this->resetValidation('photo');
    }

    public function save(): void
    {
        $permission = ['product' => 'menu.manage', 'kiosk' => 'kiosks.manage', 'staff' => 'staff.manage', 'table' => 'kiosks.manage', 'stock' => 'inventory.manage'][$this->formType] ?? null;
        abort_unless($permission, 404);
        Access::authorize($this->businessId, $permission);
        if ($this->formType === 'product') {
            $this->validate([
                'menuKiosks' => $this->allMenuKiosks ? 'array' : 'required|array|min:1',
                'menuKiosks.*' => ['integer','distinct',Rule::exists('kiosks','id')->where('business_id',$this->businessId)],
            ]);
            $product = $this->editingId
                ? Product::where('business_id', $this->businessId)->findOrFail($this->editingId)
                : new Product;
            $allowedImages = ['/images/bowl.jpg', '/images/beef.jpg', '/images/burger.jpg', '/images/chicken.jpg', '/images/fries.jpg', '/images/latte.jpg'];
            if ($product->exists) {
                $allowedImages[] = $product->image;
            }
            $validated = $this->validate([
                'form.name' => 'required|string|max:100',
                'form.category' => 'required|string|max:50',
                'form.description' => 'nullable|string|max:500',
                'form.price' => 'required|numeric|min:0.01|max:99999',
                'form.image' => ['required', Rule::in($allowedImages)],
                'form.active' => 'boolean',
                'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=6000,max_height=6000',
            ], [
                'photo.image' => 'Choose a JPG, PNG, or WebP image.',
                'photo.mimes' => 'Choose a JPG, PNG, or WebP image.',
                'photo.max' => 'The picture must be 2 MB or smaller.',
                'photo.dimensions' => 'The picture must be no larger than 6000 x 6000 pixels.',
            ]);
            $data = $validated['form'];
            $data['price'] = (int) round($data['price'] * 100);
            $data['business_id'] = $this->businessId;
            $storedPath = null;
            try {
                if ($this->photo) {
                    $storedPath = $this->photo->store('businesses/'.$this->businessId.'/menu', 'public');
                    if (! $storedPath) {
                        $storedPath = null;
                        throw \Illuminate\Validation\ValidationException::withMessages(['photo' => 'The picture could not be saved. Please check storage permissions.']);
                    }
                    $data['image'] = '/storage/'.$storedPath;
                }
                DB::transaction(function () use ($product, $data) {
                    Business::query()->lockForUpdate()->findOrFail($this->businessId);
                    if (! collect($this->menuCategories())->contains(fn (string $name) => Str::lower(trim($name)) === Str::lower(trim($data['category'])))) {
                        throw ValidationException::withMessages(['form.category' => 'Select an available category.']);
                    }

                    $product->visible_kiosk_ids = $this->allMenuKiosks ? null : array_map('intval',array_values($this->menuKiosks));
                    $product->fill($data)->save();
                    foreach (Kiosk::where('business_id', $this->businessId)->get() as $kiosk) {
                        $kiosk->products()->syncWithoutDetaching([$product->id => []]);
                    }
                });
            } catch (\Throwable $exception) {
                if ($storedPath) {
                    Storage::disk('public')->delete($storedPath);
                }
                throw $exception;
            }
        }
        if ($this->formType === 'table') {
            $this->validate(['form.name' => 'required|string|max:100', 'form.slug' => 'nullable|string|max:120']);
            $t = DiningTable::whereIn('kiosk_id', Access::kiosks(auth()->user(), $this->businessId)->pluck('id'))->findOrFail($this->editingId);
            $t->update(['name' => $this->form['name'], 'slug' => UrlSlugs::validate($t, $this->form['slug'], 'form.slug')]);
        }
        if ($this->formType === 'kiosk') {
            $this->validate(['form.slug'=>'nullable|string|max:120', 'form.name' => 'required|string|max:100', 'form.address' => 'nullable|string|max:200', 'form.active' => 'boolean', 'form.service_charge' => 'required|numeric|min:0|max:25']);
            DB::transaction(function () {
                $k = $this->editingId ? Kiosk::where('business_id', $this->businessId)->lockForUpdate()->findOrFail($this->editingId) : new Kiosk;
                $new = ! $k->exists;
                $k->business_id = $this->businessId;
                $k->slug = \App\Services\UrlSlugs::validate($k, $this->form['slug'] ?? '', 'form.slug');
                $k->fill(['business_id' => $this->businessId, 'name' => $this->form['name'], 'address' => $this->form['address'], 'active' => $this->form['active'], 'service_charge_bps' => (int) round($this->form['service_charge'] * 100)])->save();
                if ($new) {
                    $k->products()->sync(Product::where('business_id', $this->businessId)->pluck('id')->all());
                    foreach (range(1, 8) as $n) {
                        $k->tables()->create(['name' => 'Table '.str_pad($n, 2, '0', STR_PAD_LEFT), 'token' => (string) Str::uuid()]);
                    }
                }
            });
        }
        if ($this->formType === 'stock') {
            $this->validate(['form.stock' => 'required|integer|min:0|max:1000000']);
            Access::authorize($this->businessId, 'inventory.manage', $this->selectedKiosk);
            DB::transaction(function () {
                $k = Kiosk::where('business_id', $this->businessId)->lockForUpdate()->findOrFail($this->selectedKiosk);
                $p = $k->products()->where('business_id', $this->businessId)->findOrFail($this->editingId);
                $k->products()->updateExistingPivot($p->id, ['stock' => (int) $this->form['stock']]);
                BusinessAudit::record($this->businessId, 'Stock updated', $p->name, $k->id, ['Before' => $p->pivot->stock, 'After' => (int) $this->form['stock']]);
            });
        }
        if ($this->formType === 'staff') {
            $this->validate(['form.name' => 'required|string|max:100', 'form.email' => 'required|email|max:150', 'form.password' => 'nullable|string|min:12|max:128', 'form.role' => ['required', Rule::in(['admin', 'manager', 'cashier', 'kitchen'])], 'form.kiosk_ids' => 'array', 'form.kiosk_ids.*' => [Rule::exists('kiosks', 'id')->where('business_id', $this->businessId)], 'form.permissions' => 'array', 'form.permissions.*' => [Rule::in(config('kiosk.permissions'))]]);
            DB::transaction(function () {
                $business = Business::findOrFail($this->businessId);
                if ($this->editingId) {
                    $user = $business->users()->findOrFail($this->editingId);
                    abort_if($user->pivot->role === 'owner' || $user->is_super_admin || $user->id === auth()->id(), 403);
                } else {
                    $user = User::where('email', $this->form['email'])->first();
                    if (! $user) {
                        $this->validate(['form.password' => 'required|string|min:12|max:128']);
                        $user = User::create(['name' => $this->form['name'], 'email' => $this->form['email'], 'password' => $this->form['password']]);
                    }abort_if($user->is_super_admin || $user->id === auth()->id() || $business->users()->where('users.id', $user->id)->exists(), 422, 'This account already has access. Edit its existing membership.');
                }
                $business->users()->syncWithoutDetaching([$user->id => ['role' => $this->form['role'], 'permissions' => json_encode($this->form['permissions'])]]);
                $ids = Kiosk::where('business_id', $this->businessId)->pluck('id');
                $user->kiosks()->detach($ids);
                $user->kiosks()->syncWithoutDetaching($this->form['kiosk_ids']);
                BusinessAudit::record($this->businessId, $this->editingId ? 'Staff access updated' : 'Staff access granted', $user->name, details: [
                    'Role' => $this->form['role'], 'Permissions' => $this->form['permissions'], 'Kiosk IDs' => $this->form['kiosk_ids'],
                ]);
            });
        }
        $this->modal = false;
        $this->form = [];
        $this->reset('photo');
        session()->flash('success', 'Changes saved successfully.');
    }

    public function revoke(int $id): void
    {
        Access::authorize($this->businessId, 'staff.manage');
        $b = Business::findOrFail($this->businessId);
        $u = $b->users()->findOrFail($id);
        abort_if($u->pivot->role === 'owner' || $u->is_super_admin || $u->id === auth()->id(), 403);
        DB::transaction(function () use ($b, $u) {
            $u->kiosks()->detach($b->kiosks()->pluck('id'));
            $b->users()->detach($u->id);
            BusinessAudit::record($b->id, 'Staff access revoked', $u->name, details: ['Role' => $u->pivot->role]);
        });
        session()->flash('success', 'Business access removed.');
    }

    public function markPaid(int $id): void
    {
        Access::authorize($this->businessId, 'orders.pay');
        $order = Order::where('business_id', $this->businessId)->whereIn('kiosk_id', Access::kiosks(auth()->user(), $this->businessId)->pluck('id'))->findOrFail($id);
        app(\App\Services\RecordPayment::class)->settleCash($order);
    }

    public function render()
    {
        $this->authorizeSection();
        $business = Business::findOrFail($this->businessId);
        $kiosks = Access::kiosks(auth()->user(), $business->id);
        $kioskIds = $this->selectedKiosk ? [$this->selectedKiosk] : $kiosks->pluck('id')->all();
        $base = Order::where('business_id', $business->id)->whereIn('kiosk_id', $kioskIds);
        $today = (clone $base)->whereDate('created_at', today());
        $paid = (clone $today)->where('payment_status', 'paid');
        $sales = (clone $paid)->sum('total');
        $count = (clone $today)->count();
        $average = (int) ((clone $paid)->avg('total') ?? 0);
        $activeTables = (clone $today)->whereNotNull('dining_table_id')->whereNotIn('status', ['completed','cancelled'])->distinct()->count('dining_table_id');
        $orderList = (clone $base)->with('items', 'table', 'kiosk', 'payments')->latest()->orderByDesc('id');
        $orderReport = [];
        $recent = collect();
        if ($this->section === 'orders') {
            $orderReport = app(\App\Services\CashierSales::class)->report($business->id, $kioskIds, $this->appliedOrderDate ?: today()->toDateString(), $this->appliedOrderCashier, $this->appliedOrderShift);
        } elseif (in_array($this->section, ['overview', 'reports'], true)) {
            $recent = $orderList->limit(5)->get();
        }
        $products = Product::where('business_id', $business->id)->where('name', 'like', '%'.$this->search.'%')->get();
        $staff = $business->users()->get();
        $inventory = $this->selectedKiosk ? Kiosk::findOrFail($this->selectedKiosk)->products()->where('business_id', $business->id)->get() : collect();
        $tables = DiningTable::whereIn('kiosk_id', $kioskIds)->with('kiosk')->get();
        $best = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.status','!=','cancelled')->where('orders.business_id', $business->id)->whereIn('orders.kiosk_id', $kioskIds)->whereDate('orders.created_at', today())->select('order_items.name')->selectRaw('sum(quantity) as units')->groupBy('order_items.name')->orderByDesc('units')->limit(4)->get();
        $chart = [];
        foreach (range(6, 0) as $days) {
            $date = today()->subDays($days);
            $chart[] = ['label' => $date->format('D'), 'value' => (int) (clone $base)->where('payment_status', 'paid')->whereDate('created_at', $date)->sum('total')];
        }

        $toppings = $this->section === 'toppings' ? \App\Services\OrderToppings::catalog($this->businessId)->orderBy('name')->get() : collect();

        return view('livewire.workspace', compact('toppings', 'business', 'kiosks', 'sales', 'count', 'average', 'activeTables', 'orderReport', 'recent', 'products', 'staff', 'inventory', 'tables', 'best', 'chart'))->layout('layouts.app', ['title' => ucfirst($this->section)]);
    }
}
