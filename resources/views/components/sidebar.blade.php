@props(['business','mode'=>'overview','kiosk'=>null])
@php
    $staffKiosks = \App\Services\Access::kiosks(auth()->user(), $business->id)->where('active', true);
    $staffKiosk = $staffKiosks->firstWhere('id', $kiosk?->id) ?? $staffKiosks->first();
@endphp
<nav class="staff-tabs" aria-label="Mobile workspace navigation">
    <button type="button" @click="navOpen = true; $nextTick(() => $refs.staffMenuClose.focus())"
        x-ref="staffMenuTrigger" :aria-expanded="navOpen.toString()" aria-controls="staff-nav">
        <x-g-icon name="bars-3"/>Menu
    </button>
</nav>
<div class="staff-menu-backdrop" :class="{ 'is-open': navOpen }"
    @click="navOpen = false; $refs.staffMenuTrigger.focus()" aria-hidden="true"></div>
<aside id="staff-nav" class="sidebar" @keydown.escape.window="if (navOpen) { navOpen = false; $refs.staffMenuTrigger.focus() }" :class="{'mobile-open': navOpen}">
<div class="staff-sheet-header">
    <span>Staff menu</span>
    <button type="button" x-ref="staffMenuClose" aria-label="Close staff menu"
        @click="navOpen = false; $refs.staffMenuTrigger.focus()"><x-g-icon name="x-mark"/></button>
</div>
<nav class="staff-sheet-links" aria-label="Staff shortcuts">
    @foreach(['pos' => ['calculator', 'Point of Sale', 'pos.use'], 'orders' => ['clipboard-document-list', 'Orders', 'orders.view'], 'kitchen' => ['fire', 'Kitchen', 'kitchen.use']] as $tab => [$icon, $label, $permission])
        @if(\App\Services\Access::allows(auth()->user(), $business->id, $permission) && ($tab === 'orders' || $staffKiosk))
            <a href="{{ $tab === 'orders' ? route('workspace', ['business' => $business, 'tab' => 'orders']) : route($tab, [$business, $staffKiosk]) }}"
                @if($mode === $tab) aria-current="page" @endif>
                <x-g-icon :name="$icon"/><span>{{ $label }}</span>
                @if($mode === $tab)<span class="staff-current" aria-hidden="true">Current</span>@endif
                <x-g-icon name="arrow-up-right"/>
            </a>
        @endif
    @endforeach
</nav>
<div class="sidebar-top">
    <x-brand/>
    <button type="button" class="mobile-only icon-btn" @click="navOpen=false" aria-label="Close navigation"><x-g-icon name="x-mark"/></button>
</div>
<a class="workspace-tag staff-business-switch" href="{{ route('home') }}" aria-label="Switch business. Current business: {{ $business->name }}">
    @if($business->logoUrl())
        <img width="20" height="20" src="{{ $business->logoUrl() }}" alt="">
    @else
        <span class="tiny-dot"></span>
    @endif
    <span>{{ $business->name }}</span>
    <x-g-icon name="arrow-up-right"/>
</a>
<div class="nav-caption">WORKSPACE</div><nav>
@foreach(['overview'=>['home','Overview','dashboard.view'],'orders'=>['shopping-bag','Orders','orders.view'],'menu'=>['squares-2x2','Menu','menu.manage'],'inventory'=>['cube','Inventory','inventory.manage'],'kiosks'=>['building-storefront','Kiosks & QR','kiosks.manage'],'staff'=>['users','Team & access','staff.manage'],'reports'=>['chart-bar','Reports','reports.view']] as $key=>[$icon,$label,$permission])
@if(\App\Services\Access::allows(auth()->user(),$business->id,$permission))
@if(isset($this) && $this instanceof \App\Livewire\Workspace)<button wire:click="navigate('{{ $key }}')" @click="navOpen=false" class="nav-item {{ $key === 'orders' ? 'staff-order-link' : '' }} {{ $mode===$key?'active':'' }}"><x-g-icon :name="$icon"/>{{ $label }}</button>
@else<a href="{{ route('workspace',['business'=>$business,'tab'=>$key]) }}" class="nav-item {{ $key === 'orders' ? 'staff-order-link' : '' }}"><x-g-icon :name="$icon"/>{{ $label }}</a>@endif
@endif
@endforeach
@if(\App\Services\BusinessAudit::canView(auth()->user(), $business->id))
    <a href="{{ route('audit.trail', $business) }}" class="nav-item {{ $mode === 'audit' ? 'active' : '' }}" @if($mode === 'audit') aria-current="page" @endif>
        <x-g-icon name="clipboard-document-list"/>Audit Trail
    </a>
@endif
</nav>
@php
    $currentKiosk = $kiosk ?? \App\Services\Access::kiosks(auth()->user(), $business->id)->first();
    $canUsePos = $currentKiosk && \App\Services\Access::allows(auth()->user(), $business->id, 'pos.use');
    $canUseKitchen = $currentKiosk && \App\Services\Access::allows(auth()->user(), $business->id, 'kitchen.use');
    $canManageBusiness = \App\Services\Access::allows(auth()->user(), $business->id, 'kiosks.manage');
    $canManageAi = \App\Services\GatherAi::canManage(auth()->user(), $business->id);
@endphp
@if($canUsePos || $canUseKitchen)
<div class="nav-caption">OPERATIONS</div>
<nav class="staff-operations" aria-label="Operations">
    @if($canUsePos)
        <a class="nav-item {{ $mode === 'pos' ? 'active' : '' }}" href="{{ route('pos', [$business, $currentKiosk]) }}" @if($mode === 'pos') aria-current="page" @endif><x-g-icon name="calculator"/>Point of Sale</a>
    @endif
    @if($canUseKitchen)
        <a class="nav-item {{ $mode === 'kitchen' ? 'active' : '' }}" href="{{ route('kitchen', [$business, $currentKiosk]) }}" @if($mode === 'kitchen') aria-current="page" @endif><x-g-icon name="fire"/>Kitchen</a>
    @endif
</nav>
@endif
@if($canManageBusiness || $canManageAi)
<div class="nav-caption staff-settings-caption">SETTINGS</div>
<nav aria-label="Settings">
    @if($canManageBusiness)
        <a class="nav-item {{ $mode === 'settings' ? 'active' : '' }}" href="{{ route('business.settings', $business) }}" @if($mode === 'settings') aria-current="page" @endif><x-g-icon name="cog-6-tooth"/>Business settings</a>
    @endif
    @if($canManageAi)
        <a class="nav-item {{ $mode === 'ai' ? 'active' : '' }}" href="{{ route('business.ai', $business) }}" @if($mode === 'ai') aria-current="page" @endif><x-g-icon name="sparkles"/>AI settings</a>
    @endif
</nav>
@endif
<div class="sidebar-bottom"><div class="staff-menu-actions"><a href="{{ route('home') }}">Switch business</a><button type="button" data-staff-install hidden>Install workspace</button></div><div class="help-card"><x-g-icon name="sparkles"/><strong>A little more connected.</strong><p>Your kiosks. One workspace.</p><a href="{{ route('home') }}">Switch business <span>↗</span></a></div><div class="profile"><span class="avatar">{{ substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(\App\Services\Access::membership(auth()->user(),$business->id)?->pivot->role??'Super admin') }}</small></div><form method="post" action="{{ route('logout') }}">@csrf<button class="icon-btn" title="Sign out"><x-g-icon name="arrow-right-start-on-rectangle"/></button></form></div></div></aside>
