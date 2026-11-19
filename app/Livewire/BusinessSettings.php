<?php

namespace App\Livewire;

use App\Models\Business;
use App\Services\Access;
use App\Services\UrlSlugs;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BusinessSettings extends Component
{
    use \App\Livewire\Concerns\ChecksBusinessAvailability;

    #[Locked]
    public int $businessId;

    public string $name = '';

    public string $slug = '';

    public function mount(Business $business): void
    {
        $this->businessId = $business->id;
        Access::authorize($business->id, 'kiosks.manage');
        $this->name = $business->name;
        $this->slug = $business->slug;
    }

    public function save(): void
    {
        Access::authorize($this->businessId, 'kiosks.manage');
        $this->validate(['name' => 'required|string|max:100', 'slug' => 'nullable|string|max:120']);
        $business = Business::findOrFail($this->businessId);
        $slug = UrlSlugs::validate($business, $this->slug);
        DB::transaction(fn () => $business->update(['name' => $this->name, 'slug' => $slug]));
        session()->flash('business_saved', 'Business details saved. Older links will redirect.');
        $this->redirectRoute('business.settings', ['business' => $business]);
    }

    public function render()
    {
        Access::authorize($this->businessId, 'kiosks.manage');
        $business = Business::findOrFail($this->businessId);

        return view('livewire.business-settings', compact('business'))->layout('layouts.app', ['title' => 'Business settings']);
    }
}
