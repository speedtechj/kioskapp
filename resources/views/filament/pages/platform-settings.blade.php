<x-filament-panels::page>
    <div class="control-page">
        <nav class="control-tabs" aria-label="Platform settings sections">
            <button type="button" wire:click="$set('tab', 'branding')" aria-pressed="{{ $tab === 'branding' ? 'true' : 'false' }}">Branding</button>
            <button type="button" wire:click="$set('tab', 'admins')" aria-pressed="{{ $tab === 'admins' ? 'true' : 'false' }}">Super admins</button>
        </nav>

        @if($tab === 'admins')
            <p class="control-notice">Super admins can manage all businesses, kiosks, and platform settings.</p>
            <div class="control-grid">
                <x-filament::section heading="Super admins">
                    <div class="control-scroll"><table class="control-table">
                        <thead><tr><th>Full name</th><th>Email address</th></tr></thead>
                        <tbody>@foreach($this->getSuperAdmins() as $admin)
                            <tr wire:key="admin-{{ $admin->id }}"><td><strong>{{ $admin->name }}</strong> @if($admin->id === auth()->id())<span class="control-status">You</span>@endif</td><td>{{ $admin->email }}</td></tr>
                        @endforeach</tbody>
                    </table></div>
                </x-filament::section>
                <x-filament::section heading="Add super admin">
                    <form class="control-form" wire:submit="createSuperAdmin">
                        <x-control-input field="adminName" label="Full name" autocomplete="off"/>
                        <x-control-input field="adminEmail" label="Email address" type="email" autocomplete="off"/>
                        <x-control-input field="adminPassword" label="Initial password (at least 12 characters)" type="password" autocomplete="new-password"/>
                        <x-control-input field="adminPassword_confirmation" label="Confirm password" type="password" autocomplete="new-password"/>
                        <x-filament::button type="submit">Create super admin</x-filament::button>
                    </form>
                </x-filament::section>
            </div>
        @else
            @php($branding = \App\Models\PlatformBranding::current())
            <div class="control-grid">
                <x-filament::section heading="Platform branding">
                    <form class="control-form" wire:submit="saveBranding">
                        <x-control-input field="platformName" label="Platform name" :live="true"/>
                        <x-control-upload field="logoUpload" label="Platform logo" :url="$branding->imageUrl('logo')" :upload="$logoUpload"/>
                        <x-control-upload field="faviconUpload" label="Browser favicon" :url="$branding->imageUrl('favicon')" :upload="$faviconUpload" hint="Square image, at least 32 × 32 pixels"/>
                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="logoUpload,faviconUpload,saveBranding">Save changes</x-filament::button>
                    </form>
                </x-filament::section>
                <div class="control-stack">
                    <x-filament::section heading="Preview">
                        <div class="control-form">
                            <div><p>Admin header</p><span class="control-brand control-preview"><img src="{{ $logoUpload instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && $logoUpload->isPreviewable() ? $logoUpload->temporaryUrl() : $branding->imageUrl('logo') }}" alt=""><strong>{{ $platformName }}</strong></span></div>
                            <div><p>Browser tab</p><span class="control-brand control-preview"><img width="20" height="20" src="{{ $faviconUpload instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && $faviconUpload->isPreviewable() ? $faviconUpload->temporaryUrl() : $branding->imageUrl('favicon') }}" alt="">{{ $platformName }}</span></div>
                        </div>
                    </x-filament::section>
                    <x-filament::section heading="Where this appears">
                        <ul class="control-stack"><li>Admin sidebar</li><li>Admin sign-in</li><li>Browser tab</li></ul>
                        <a class="control-link" href="{{ \App\Filament\Pages\Businesses::getUrl() }}">Manage business and kiosk logos</a>
                    </x-filament::section>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
