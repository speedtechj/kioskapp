<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('branding/business-availability.css') }}">
    @php
        $selected = $this->selectedBusiness();
        $kiosks = $selected?->kiosks()->withExists('orders')->orderBy('name')->get() ?? collect();
        $logoKiosk = $kiosks->firstWhere('id', $logoKioskId);
        $totals = $this->getTotals();
        $defaultLogo = asset('branding/bowl-192.png');
    @endphp
    <div class="control-page">
        <div class="control-row control-intro">
            <p>Manage businesses, kiosks, and branding.</p>
            <x-filament::modal id="create-business" heading="Create a business">
                <x-slot name="trigger"><x-filament::button icon="heroicon-o-plus">Create business</x-filament::button></x-slot>
                <form class="control-form" wire:submit="createBusiness">
                    <x-control-input field="name" label="Business name"/>
                    <x-control-input field="businessSlug" label="Business URL slug (optional)" :required="false"/>
                    <x-control-input field="ownerName" label="Owner name"/>
                    <x-control-input field="email" label="Owner email" type="email"/>
                    <x-control-input field="password" label="Initial password (at least 12 characters)" type="password" autocomplete="new-password"/>
                    <x-filament::button type="submit">Create business</x-filament::button>
                </form>
            </x-filament::modal>
        </div>
        <div class="control-stats">
            @foreach(['Businesses','Kiosks','Businesses without kiosks'] as $index => $label)
                <div class="control-stat"><span>{{ $label }}</span><strong>{{ $totals[$index] }}</strong></div>
            @endforeach
        </div>
        <div class="control-grid control-business-grid">
            <div class="control-stack">
                <x-filament::section heading="Businesses">
                    <div class="control-form">
                        <x-control-input field="search" label="Search by business name or slug" :live="true" :required="false"/>
                        <div class="control-scroll"><table class="control-table availability-table">
                            <thead><tr><th>Business</th><th>Slug</th><th>Kiosks</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                            <tbody>
                            @forelse($this->getBusinesses() as $business)
                                <tr wire:key="business-{{ $business->id }}" class="{{ $selectedId === $business->id ? 'control-selected' : '' }}">
                                    <td><button type="button" wire:click="selectBusiness({{ $business->id }})" aria-pressed="{{ $selectedId === $business->id ? 'true' : 'false' }}" class="control-brand"><img src="{{ $business->logoUrl() ?? $defaultLogo }}" alt=""><strong>{{ $business->name }}</strong></button></td>
                                    <td class="availability-extra">{{ $business->slug }}</td><td class="availability-extra">{{ $business->kiosks_count }}</td>
                                    <td><span @class(['availability-badge', 'availability-'.$business->availability])>{{ \App\Services\BusinessAvailability::LABELS[$business->availability] }}</span></td>
                                    <td>{{ ($this->changeAvailabilityAction)(['business' => $business->id]) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5">No matching businesses.</td></tr>
                            @endforelse
                            </tbody>
                        </table></div>
                    </div>
                </x-filament::section>
                <x-filament::section heading="Platform branding">
                    <div class="control-row"><p>Change the control panel’s name, logo, and browser icon.</p><x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\PlatformSettings::getUrl()">Edit platform branding</x-filament::button></div>
                </x-filament::section>
            </div>
            @if($selected)
                <div class="control-stack" wire:key="selected-{{ $selected->id }}">
                    <x-filament::section :heading="$selected->name">
                        <x-slot name="afterHeader">
                            <x-filament::button size="sm" color="gray" tag="a" :href="route('workspace', $selected)">Open workspace</x-filament::button>
                        </x-slot>
                        <form class="control-form" wire:submit="saveBusinessDetails">
                            <x-control-input field="businessName" label="Business name"/>
                            <x-control-upload field="businessLogo" label="Business logo" :url="$selected->logoUrl() ?? $defaultLogo" :upload="$businessLogo"/>
                            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="businessLogo,saveBusinessDetails">Save business details</x-filament::button>
                        </form>
                        @if($this->canDeleteBusiness($selected))
                            <x-filament::modal :id="'delete-business-'.$selected->id" :heading="'Delete '.$selected->name.'?'">
                                <x-slot name="trigger">
                                    <x-filament::button type="button" color="danger" outlined>Delete business</x-filament::button>
                                </x-slot>
                                <p>This permanently removes the business <strong>{{ $selected->slug }}</strong> and its owner assignments. Owner login accounts are kept.</p>
                                <x-slot name="footer">
                                    <x-filament::button type="button" color="danger" wire:click="deleteBusiness({{ $selected->id }})" wire:loading.attr="disabled">Delete business</x-filament::button>
                                </x-slot>
                            </x-filament::modal>
                        @endif
                    </x-filament::section>
                    <x-filament::section heading="Owner account">
                        <div class="control-form">
                            @forelse($this->businessOwners() as $owner)
                                <div class="control-form" wire:key="business-owner-{{ $selected->id }}-{{ $owner->id }}">
                                    <dl>
                                        <dt>Owner name</dt>
                                        <dd><strong>{{ $owner->name }}</strong></dd>
                                        <dt>Sign-in email</dt>
                                        <dd><strong class="break-all">{{ $owner->email }}</strong></dd>
                                    </dl>
                                    @unless($owner->is_super_admin)
                                        <div class="control-actions">
                                            {{ ($this->editOwnerEmailAction)(['business' => $selected->id, 'owner' => $owner->id])->label('Edit owner email')->color('gray')->outlined() }}
                                            {{ ($this->resetOwnerPasswordAction)(['business' => $selected->id, 'owner' => $owner->id])->label('Reset owner password')->color('gray')->outlined() }}
                                        </div>
                                    @endunless
                                </div>
                            @empty
                                <p>This business has no owner account.</p>
                            @endforelse
                            <p>Changes apply to every business linked to the owner account.</p>
                        </div>
                    </x-filament::section>
                    <x-filament::section heading="Kiosks">
                        <div class="control-form">
                            @forelse($kiosks as $kiosk)
                                <div class="control-kiosk" wire:key="kiosk-{{ $kiosk->id }}">
                                    <div class="control-row">
                                        <button type="button" class="control-brand" wire:click="selectKiosk({{ $kiosk->id }})" aria-pressed="{{ $logoKioskId === $kiosk->id ? 'true' : 'false' }}"><img src="{{ $kiosk->logoUrl() }}" alt=""><strong>{{ $kiosk->name }}</strong></button>
                                        <span class="control-status {{ $kiosk->active ? 'control-active' : '' }}">{{ $kiosk->archived_at ? 'Archived' : ($kiosk->active ? 'Active' : 'Paused') }}</span>
                                    </div>
                                    <div class="control-actions">
                                        <button type="button" wire:click="selectKiosk({{ $kiosk->id }})">Edit logo</button>
                                        <a href="{{ route('kiosk.brand', [$selected, $kiosk]) }}">QR &amp; app branding</a>
                                        @unless($kiosk->archived_at)
                                            <button type="button" wire:click="toggleKiosk({{ $kiosk->id }})" wire:loading.attr="disabled">{{ $kiosk->active ? 'Pause' : 'Activate' }}</button>
                                            <x-filament::modal :id="'remove-kiosk-'.$kiosk->id" :heading="($kiosk->orders_exists ? 'Archive ' : 'Delete ').$kiosk->name.'?'">
                                                <x-slot name="trigger"><button type="button" class="control-error">{{ $kiosk->orders_exists ? 'Archive kiosk' : 'Delete kiosk' }}</button></x-slot>
                                                <p>{{ $kiosk->orders_exists ? 'Ordering and customer QR links will stop working for this kiosk. Past orders and payment records will be preserved.' : 'This removes the kiosk, its tables, and kiosk assignments. Existing QR links will stop working.' }}</p>
                                                <x-slot name="footer">
                                                    <x-filament::button type="button" color="danger" wire:click="removeKiosk({{ $kiosk->id }})" wire:loading.attr="disabled">{{ $kiosk->orders_exists ? 'Archive kiosk' : 'Delete kiosk' }}</x-filament::button>
                                                </x-slot>
                                            </x-filament::modal>
                                        @endunless
                                    </div>
                                </div>
                            @empty
                                <p>This business has no kiosks yet.</p>
                            @endforelse
                            @if($logoKiosk)
                                <form class="control-form" wire:key="logo-{{ $logoKiosk->id }}" wire:submit="saveKioskLogo">
                                    <x-control-upload field="kioskLogo" :label="$logoKiosk->name.' logo'" :url="$logoKiosk->logoUrl()" :upload="$kioskLogo" hint="Square image, at least 192 × 192 pixels"/>
                                    <div class="control-actions">
                                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="kioskLogo,saveKioskLogo">Save kiosk logo</x-filament::button>
                                        @if($logoKiosk->app_icon_key)<x-filament::button type="button" color="gray" wire:click="useBusinessLogo">Use business logo</x-filament::button>@endif
                                    </div>
                                </form>
                            @endif
                            <form class="control-form" wire:submit="addKiosk">
                                <x-control-input field="kioskName" label="New kiosk name"/>
                                <x-filament::button type="submit" color="gray" icon="heroicon-o-plus">Add kiosk</x-filament::button>
                            </form>
                        </div>
                    </x-filament::section>
                </div>
            @else
                <x-filament::section heading="Get started"><p>Create a business to manage its branding and kiosks.</p></x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
