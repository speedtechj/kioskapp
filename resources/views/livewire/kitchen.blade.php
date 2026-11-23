@assets
<link rel="stylesheet" href="{{ asset('branding/kitchen-tickets.css').'?v=2' }}">
@endassets
<div class="app-shell kitchen-shell" x-data="{navOpen:false}">
    <x-sidebar :business="$business" :kiosk="$kiosk" mode="kitchen"/>
    <main class="main kitchen-display" wire:poll.5s>
        <header class="topbar">
            <div class="heading-row">
                <button class="icon-btn mobile-only" @click="navOpen=true" aria-label="Open navigation"><x-g-icon name="bars-3"/></button>
                <div><h1>Kitchen<span class="kitchen-desktop-label"> Display</span><span class="brand-dot">.</span></h1><p class="kitchen-desktop-label">Active tickets</p></div>
            </div>
            <span class="branch-chip"><span class="tiny-dot"></span>{{ $kiosk->name }} · Live</span>
        </header>
        <div class="kitchen-view-switch" role="group" aria-label="Kitchen ticket view">
            <button type="button" class="btn {{ $view === 'tables' ? 'primary' : '' }}" aria-pressed="{{ $view === 'tables' ? 'true' : 'false' }}" wire:click="showTickets('tables')">
                <x-g-icon name="table-cells"/> Table Groups <span>{{ $tableGroups->sum(fn ($tickets) => $tickets->count()) }}</span>
            </button>
            <button type="button" class="btn {{ $view === 'takeout' ? 'primary' : '' }}" aria-pressed="{{ $view === 'takeout' ? 'true' : 'false' }}" wire:click="showTickets('takeout')">
                <x-g-icon name="shopping-bag"/> Takeout <span>{{ $takeoutOrders->count() }}</span>
            </button>
        </div>
        @error('pickup')<p role="alert" class="error">{{ $message }}</p>@enderror
        @if($view === 'tables')
            <div class="kitchen-table-groups" wire:key="kitchen-tables">
                @forelse($tableGroups as $tableId => $tickets)
                    <section class="kitchen-table-group" wire:key="kitchen-table-{{ $tableId }}">
                        <h2>{{ $tickets->first()->table?->name ?? 'Table '.$tableId }}</h2>
                        <div class="kitchen-table-tickets">
                            @foreach($tickets as $order)
                                <x-kitchen-order-ticket :order="$order" :can-advance="$canAdvance" />
                            @endforeach
                        </div>
                    </section>
                @empty
                    <p class="card">No active table orders.</p>
                @endforelse
            </div>
        @else
            <section wire:key="kitchen-takeout">
                <h2 class="kitchen-takeout-title"><x-g-icon name="shopping-bag"/> Takeout</h2>
                <div class="kitchen-takeout-grid">
                    @forelse($takeoutOrders as $order)
                        <x-kitchen-order-ticket :order="$order" :can-advance="$canAdvance" />
                    @empty
                        <p class="card">No active takeout orders.</p>
                    @endforelse
                </div>
            </section>
        @endif
    </main>
</div>
