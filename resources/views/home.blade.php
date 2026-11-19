@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('branding/workspace-selector.css') }}">
<div class="workspace-selector">
    <header class="workspace-header">
        <div class="workspace-header-inner">
            <a class="workspace-wordmark" href="{{ route('home') }}">Gather<span>.</span></a>
            <div class="workspace-desktop-account">
                @if($user->is_super_admin)
                    <a href="{{ url('/control/businesses') }}">Platform administration</a>
                @elseif($cashierOnly)
                    <span>Cashier</span>
                @endif
                <span class="workspace-avatar" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button class="workspace-signout" type="submit">Sign out</button>
                </form>
            </div>
            <details class="workspace-account-menu">
                <summary aria-label="Account menu">
                    <span class="workspace-avatar" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
                    <x-g-icon name="chevron-down"/>
                </summary>
                <div class="workspace-account-links">
                    @if($user->is_super_admin)
                        <a href="{{ url('/control/businesses') }}">Platform administration</a>
                    @elseif($cashierOnly)
                        <span>Cashier</span>
                    @endif
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="workspace-signout" type="submit">Sign out</button>
                    </form>
                </div>
            </details>
        </div>
    </header>
    <main class="workspace-directory">
        <h1>{{ $cashierOnly ? 'Your kiosks' : 'Your workspaces' }}</h1>
        <p>{{ $cashierOnly ? 'Choose a kiosk to open the POS.' : 'Welcome, '.str($user->name)->before(' ').'. Choose a business to get started.' }}</p>
        <div class="workspace-list">
            @forelse($workspaces as $workspace)
                @php
                    ['business' => $business, 'kiosks' => $kiosks, 'dashboard' => $dashboard, 'pos' => $pos, 'kitchen' => $kitchen] = $workspace;
                    $expanded = $cashierOnly || $loop->first;
                @endphp
                <article @class(['workspace-card', 'workspace-cashier-card' => $cashierOnly, 'workspace-initially-expanded' => $expanded])
                    @unless($cashierOnly)
                        x-data="{ expanded: @js($expanded) }" :class="{ 'workspace-expanded': expanded }"
                    @endunless>
                    <div class="workspace-card-heading">
                        <div class="workspace-business-icon">
                            @if($logo = $business->logoUrl())
                                <img src="{{ $logo }}" alt="" width="44" height="44">
                            @else
                                <x-g-icon name="building-storefront"/>
                            @endif
                        </div>
                        <div class="workspace-business">
                            <h2>{{ $business->name }}</h2>
                            @unless($cashierOnly)
                                <p>{{ $kiosks->isEmpty() ? 'No kiosks assigned' : $kiosks->count().' '.Str::plural('kiosk', $kiosks->count()) }}</p>
                            @endunless
                        </div>
                        @unless($cashierOnly)
                            <button class="workspace-toggle" type="button" @click="expanded = !expanded" :aria-expanded="expanded.toString()"
                                aria-controls="workspace-{{ $business->id }}" aria-label="Kiosk and dashboard links for {{ $business->name }}">
                                <x-g-icon name="chevron-down"/>
                            </button>
                        @endunless
                    </div>
                    <div class="workspace-card-content" id="workspace-{{ $business->id }}">
                        @if($dashboard)
                            <a class="workspace-action workspace-primary workspace-dashboard" href="{{ route('workspace', $business) }}">
                                Open dashboard <x-g-icon name="arrow-right"/>
                            </a>
                        @endif
                        @foreach($kiosks as $kiosk)
                            <div class="workspace-kiosk">
                                <strong>{{ $kiosk->name }}</strong>
                                <div class="workspace-kiosk-actions">
                                    @if($pos)
                                        <a @class(['workspace-action', 'workspace-primary' => $cashierOnly]) href="{{ route('pos', [$business, $kiosk]) }}"
                                            aria-label="Open POS for {{ $kiosk->name }}">
                                            @if($cashierOnly)
                                                Open POS <x-g-icon name="arrow-right"/>
                                            @else
                                                <x-g-icon name="calculator"/> POS
                                            @endif
                                        </a>
                                    @endif
                                    @if($kitchen)
                                        <a class="workspace-action" href="{{ route('kitchen', [$business, $kiosk]) }}" aria-label="Open kitchen for {{ $kiosk->name }}">
                                            <x-g-icon name="fire"/> Kitchen
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        @if($cashierOnly && $kiosks->isEmpty())
                            <p class="workspace-empty">No active kiosks assigned. Ask your administrator for access.</p>
                        @endif
                    </div>
                </article>
            @empty
                <p class="workspace-card workspace-empty">No business access yet. Ask your administrator to add you.</p>
            @endforelse
        </div>
    </main>
</div>
@endsection
