<?php

namespace App\Livewire;

use App\Models\Business;
use App\Models\BusinessAiSetting;
use App\Models\Order;
use App\Models\Product;
use App\Services\Access;
use App\Services\GatherAi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class AiSettings extends Component
{
    use \App\Livewire\Concerns\ChecksBusinessAvailability;

    #[Locked]
    public int $businessId;

    #[Url]
    public string $provider = 'openai';

    public bool $enabled = false;

    public bool $pauseAtLimit = true;

    public string $monthlyLimit = '10.00';

    public array $helpers = [];

    public string $period = 'current';

    #[Url]
    public int $productId = 0;

    public array $languages = ['fil'];

    public string $sourceLanguage = 'en';

    public array $drafts = [];

    public string $reportDate = '';

    public int $reportKiosk = 0;

    public string $question = '';

    public string $report = '';

    public function mount(Business $business): void
    {
        $this->businessId = $business->id;
        $this->authorizeManager();
        $settings = GatherAi::settings($business->id);
        $this->enabled = $settings->enabled;
        $this->pauseAtLimit = $settings->pause_at_limit;
        $this->monthlyLimit = number_format($settings->monthly_limit_cents / 100, 2, '.', '');
        foreach (config('gather-ai.helpers') as $id => $label) {
            $this->helpers[$id] = $settings->helpers[$id] ?? ['provider' => '', 'enabled' => false];
        }
        $this->reportDate = now()->toDateString();
        $this->updatedProductId();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->authorizeManager();
        GatherAi::settings($this->businessId)->update(['enabled' => $enabled, 'master_configured' => true]);
        $this->enabled = $enabled;
    }

    public function saveBudget(): void
    {
        $this->authorizeManager();
        $this->validate(['monthlyLimit' => 'required|decimal:0,2|between:0,10000']);
        GatherAi::settings($this->businessId)->update([
            'pause_at_limit' => $this->pauseAtLimit, 'monthly_limit_cents' => (int) round((float) $this->monthlyLimit * 100),
        ]);
        session()->flash('ai_notice', 'Monthly usage settings saved.');
    }

    public function saveHelper(string $helper): void
    {
        $this->authorizeManager();
        abort_unless(isset(config('gather-ai.helpers')[$helper]), 404);
        $path = "helpers.$helper";
        $this->validate([
            "$path.provider" => ['nullable', Rule::in(array_keys(config('gather-ai.providers')))],
            "$path.enabled" => 'required|boolean',
        ]);

        DB::transaction(function () use ($helper) {
            $settings = BusinessAiSetting::lockForUpdate()->findOrFail($this->businessId);
            $selected = $this->helpers[$helper];
            if ($selected['enabled'] && empty($settings->credentials[$selected['provider']])) {
                throw ValidationException::withMessages(['ai' => 'Connect the selected provider before enabling this helper.']);
            }

            $helpers = $settings->helpers ?? [];
            $helpers[$helper] = ['provider' => $selected['provider'], 'enabled' => (bool) $selected['enabled']];
            $settings->update(['helpers' => $helpers]);
        });
        session()->flash('ai_notice', 'Helper settings saved.');
    }

    public function updatedProductId(): void
    {
        $this->authorizeManager();
        $product = $this->products()->find($this->productId);
        $this->drafts = $product?->translation_drafts ?? [];
    }

    public function generateDrafts(GatherAi $ai): void
    {
        $this->authorizeManager();
        $languages = config('gather-ai.languages');
        $this->validate([
            'sourceLanguage' => ['required', Rule::in(array_keys($languages))],
            'languages' => 'required|array|min:1|max:6',
            'languages.*' => ['distinct', Rule::in(array_keys($languages)), Rule::notIn([$this->sourceLanguage])],
        ]);
        $product = $this->products()->findOrFail($this->productId);
        $targets = array_intersect_key($languages, array_flip($this->languages));
        $generated = $ai->generate($this->businessId, 'menu',
            'Translate this restaurant item accurately. Preserve only the supplied facts, never invent ingredients or allergen claims. Return only one JSON object, keyed by target language codes, each with string name and description. Keep each name under 150 characters and description under 1000.',
            json_encode(['source_language' => $languages[$this->sourceLanguage], 'name' => $product->name, 'description' => $product->description, 'targets' => $targets], JSON_THROW_ON_ERROR));
        $raw = preg_replace('/\A```(?:json)?\s*|\s*```\z/i', '', $generated);
        $decoded = json_decode($raw, true);
        $drafts = [];
        foreach ($targets as $locale => $label) {
            $draft = $decoded[$locale] ?? [];
            if (! is_string($draft['name'] ?? null) || trim($draft['name']) === '' || ! is_string($draft['description'] ?? null)) {
                throw ValidationException::withMessages(['ai' => 'The provider returned an incomplete draft. Try generating it again.']);
            }
            $drafts[$locale] = ['name' => mb_substr(trim($draft['name']), 0, 150), 'description' => mb_substr(trim($draft['description']), 0, 1000)];
        }

        $this->drafts = array_replace($this->drafts, $drafts);
    }

    public function storeDraft(string $locale, bool $publish = false): void
    {
        $this->authorizeManager();
        abort_unless(isset(config('gather-ai.languages')[$locale]), 404);
        $base = "drafts.$locale";
        $this->validate(["$base.name" => 'required|string|max:150', "$base.description" => 'nullable|string|max:1000']);
        $entry = ['name' => trim($this->drafts[$locale]['name']), 'description' => trim($this->drafts[$locale]['description'] ?? '')];
        DB::transaction(function () use ($locale, $publish, $entry) {
            $product = $this->products()->lockForUpdate()->findOrFail($this->productId);
            $drafts = $product->translation_drafts ?? [];
            $drafts[$locale] = $entry;
            $product->translation_drafts = $drafts;
            if ($publish) {
                $published = $product->published_translations ?? [];
                $published[$locale] = $entry;
                $product->published_translations = $published;
            }
            $product->save();
        });
        session()->flash('ai_notice', $publish ? 'Translation published to the customer menus.' : 'Draft saved.');
    }

    public function runReport(string $helper, GatherAi $ai): void
    {
        $this->authorizeManager();
        abort_unless(in_array($helper, ['brief', 'ask'], true), 404);
        if ($helper === 'ask') {
            $this->validate(['question' => 'required|string|max:500']);
        }
        $this->validate(['reportDate' => 'required|date_format:Y-m-d']);
        $kiosks = Access::kiosks(auth()->user(), $this->businessId);
        abort_unless($this->reportKiosk === 0 || $kiosks->contains('id', $this->reportKiosk), 403);
        $today = Carbon::parse($this->reportDate)->startOfDay();
        $orders = Order::query()->where('business_id', $this->businessId)
            ->whereIn('kiosk_id', $kiosks->pluck('id'))
            ->when($this->reportKiosk, fn ($query) => $query->where('kiosk_id', $this->reportKiosk))
            ->where('created_at', '>=', $today)->where('created_at', '<', $today->copy()->addDay());
        $facts = [
            'date' => $today->toDateString(),
            'currency' => 'PHP',
            'top_sellers' => DB::table('order_items')->whereIn('order_id', (clone $orders)->where('status', '!=', 'cancelled')->select('id'))
                ->select('name')->selectRaw('SUM(quantity) as units')->groupBy('name')->orderByDesc('units')->limit(5)->get(),
            'orders' => (clone $orders)->count(),
            'paid_orders' => (clone $orders)->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->count(),
            'paid_order_total_php' => (clone $orders)->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->sum('total') / 100,
            'unpaid_or_partial_takeout_orders' => (clone $orders)->where('source', 'takeout')->where('status', '!=', 'cancelled')->whereIn('payment_status', ['unpaid', 'partial'])->count(),
            'refunded_orders' => (clone $orders)->where('payment_status', 'refunded')->count(),
            'discounts_php' => (clone $orders)->where('status', '!=', 'cancelled')->sum('discount_amount') / 100,
            'complimentary_php' => (clone $orders)->where('status', '!=', 'cancelled')->sum('complimentary_amount') / 100,
        ];
        $this->report = '';
        $this->report = $ai->generate($this->businessId, $helper,
            'Use only the supplied aggregate order figures for the selected date and accessible kiosks. Give a short plain-text answer. If a requested figure is unavailable, say so. Do not invent facts or imply that you changed records.',
            json_encode(['question' => $helper === 'ask' ? $this->question : 'Summarize the orders for the selected date.', 'facts' => $facts], JSON_THROW_ON_ERROR));
    }

    public function render()
    {
        $this->authorizeManager();
        $business = Business::findOrFail($this->businessId);
        $settings = GatherAi::settings($business->id);
        $providers = config('gather-ai.providers');
        $provider = isset($providers[$this->provider]) ? $this->provider : 'openai';
        $keySuffixes = array_map(fn ($key) => substr($key, -4), $settings->credentials ?? []);
        $currentUsage = GatherAi::usage($business->id);
        $monthlyCost = (int) (clone $currentUsage)->sum('cost_micros');
        $monthlyRequests = $currentUsage->count();
        $usage = GatherAi::usage($business->id, $this->period === 'previous' ? 1 : 0)
            ->select('helper')->selectRaw('COUNT(*) as requests, SUM(cost_micros) as cost')
            ->groupBy('helper')->get()->keyBy('helper');
        $reportKiosks = Access::kiosks(auth()->user(), $this->businessId);
        $products = $this->products()->orderBy('name')->get(['id', 'name']);
        $selectedProduct = $this->products()->find($this->productId);

        return view('livewire.ai-settings', compact('business', 'providers', 'provider', 'keySuffixes', 'monthlyCost', 'monthlyRequests', 'usage', 'products', 'selectedProduct', 'reportKiosks'))
            ->layout('layouts.app', ['title' => 'AI settings', 'aiSettings' => true]);
    }

    private function authorizeManager(): void
    {
        abort_unless(GatherAi::canManage(auth()->user(), $this->businessId), 403);
    }

    private function products()
    {
        return Product::query()->where('business_id', $this->businessId);
    }
}
