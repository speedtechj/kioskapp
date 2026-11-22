<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\MenuPromotion;
use App\Services\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MenuPromotionController
{
    public function edit(Business $business): View
    {
        Access::authorize($business->id, 'promotions.manage');

        return view('promotions.edit', [
            'business' => $business,
            'promotion' => MenuPromotion::where('business_id', $business->id)->first(),
            'products' => $business->products()->where('active', true)->orderBy('name')->get(['id', 'name', 'price']),
        ]);
    }

    public function save(Request $request, Business $business): RedirectResponse
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

        DB::transaction(function () use ($business, $data, $selected) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $data['product_ids'] = $selected ? array_map('intval', $data['product_ids']) : [];
            MenuPromotion::updateOrCreate(['business_id' => $business->id], $data);
        });

        return redirect()->route('promotions.edit', $business)->with('promo_saved', 'Promotion saved.');
    }
}
