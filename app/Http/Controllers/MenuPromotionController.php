<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\MenuPromotion;
use App\Services\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MenuPromotionController
{
    public function index(Request $request, Business $business): View
    {
        Access::authorize($business->id, 'promotions.manage');

        $statuses = ['all' => 'All', 'active' => 'Active', 'upcoming' => 'Upcoming', 'ended' => 'Ended', 'disabled' => 'Disabled'];
        $status = $request->query('status', 'all');
        $status = is_string($status) && isset($statuses[$status]) ? $status : 'all';
        $search = trim(mb_substr($request->string('q')->toString(), 0, 100));
        $now = now();
        $query = MenuPromotion::where('business_id', $business->id)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
        match ($status) {
            'active' => $query->where('enabled', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            'upcoming' => $query->where('enabled', true)->where('starts_at', '>', $now),
            'ended' => $query->where('enabled', true)->where('ends_at', '<=', $now),
            'disabled' => $query->where('enabled', false),
            default => null,
        };

        $promotions = $query->latest('starts_at')->orderByDesc('id')->paginate(15)->withQueryString();

        return view('promotions.index', compact('business', 'promotions', 'statuses', 'status', 'search'));
    }

    public function create(Business $business): View
    {
        Access::authorize($business->id, 'promotions.manage');

        return $this->editor($business, null);
    }

    public function show(Business $business, int $promotion): View
    {
        Access::authorize($business->id, 'promotions.manage');
        $promotion = $this->findPromotion($business, $promotion);
        $products = $business->products()->where('active', true)
            ->when(! $promotion->all_items, fn ($query) => $query->whereIn('id', $promotion->product_ids))
            ->orderBy('name')->get(['id', 'name', 'price']);

        return view('promotions.show', compact('business', 'promotion', 'products'));
    }

    public function edit(Business $business, int $promotion): View
    {
        Access::authorize($business->id, 'promotions.manage');

        return $this->editor($business, $this->findPromotion($business, $promotion));
    }

    private function editor(Business $business, ?MenuPromotion $promotion): View
    {
        return view('promotions.edit', [
            'business' => $business,
            'promotion' => $promotion,
            'products' => $business->products()->where('active', true)->orderBy('name')->get(['id', 'name', 'price']),
        ]);
    }

    private function findPromotion(Business $business, int $promotion): MenuPromotion
    {
        return MenuPromotion::where('business_id', $business->id)->findOrFail($promotion);
    }

    public function store(Request $request, Business $business): RedirectResponse
    {
        return $this->persist($request, $business, null);
    }

    public function update(Request $request, Business $business, int $promotion): RedirectResponse
    {
        return $this->persist($request, $business, $promotion);
    }

    public function save(Request $request, Business $business): RedirectResponse
    {
        return $this->persist($request, $business, null, legacy: true);
    }

    private function persist(Request $request, Business $business, ?int $promotion, bool $legacy = false): RedirectResponse
    {
        Access::authorize($business->id, 'promotions.manage');
        $selected = ! $request->boolean('all_items');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'percent' => ['required', 'integer', 'between:1,100'],
            'all_items' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'announces_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i', 'after_or_equal:announces_at'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'product_ids' => [Rule::requiredIf($selected), 'array', 'max:1000'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('business_id', $business->id)->where('active', true)],
        ]);

        foreach (['announces_at', 'starts_at', 'ends_at'] as $field) {
            $data[$field] = Carbon::createFromFormat('!Y-m-d\TH:i', $data[$field])->toDateTimeString();
        }

        $saved = DB::transaction(function () use ($business, $data, $selected, $promotion, $legacy) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $existing = $promotion === null ? null : $this->findPromotion($business, $promotion);
            if ($legacy) {
                $records = MenuPromotion::where('business_id', $business->id)->limit(2)->get();
                if ($records->count() > 1) {
                    throw ValidationException::withMessages(['name' => 'Open the promotion you want to edit from the list.']);
                }
                $existing = $records->first();
            }

            $data['product_ids'] = $selected ? array_map('intval', $data['product_ids']) : [];
            if ($data['enabled'] && MenuPromotion::where('business_id', $business->id)->where('enabled', true)
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
                ->where('starts_at', '<', $data['ends_at'])
                ->where('ends_at', '>', $data['starts_at'])->exists()) {
                throw ValidationException::withMessages(['starts_at' => 'This discount overlaps another enabled promotion. Choose a different discount period.']);
            }

            $record = $existing ?? new MenuPromotion(['business_id' => $business->id]);
            $record->fill($data)->save();

            return $record;
        });

        return redirect()->route($legacy ? 'promotions.edit' : 'promotions.show', $legacy ? [$business] : [$business, $saved])
            ->with('promo_saved', 'Promotion saved.');
    }

    public function destroy(Business $business, int $promotion): RedirectResponse
    {
        Access::authorize($business->id, 'promotions.manage');

        DB::transaction(function () use ($business, $promotion) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $this->findPromotion($business, $promotion)->delete();
        });

        return redirect()->route('promotions.edit', $business)->with('promo_saved', 'Promotion deleted.');
    }
}
