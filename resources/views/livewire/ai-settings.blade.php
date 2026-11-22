<div class="app-shell" x-data="{ navOpen: false }">
    <x-sidebar :business="$business" mode="ai"/>
    <main class="main ai-settings">
        <header class="topbar">
            <div class="heading-row">
                <button class="icon-btn mobile-only" type="button" @click="navOpen = true" aria-label="Open navigation"><x-g-icon name="bars-3"/></button>
                <div><h1>AI settings<span class="brand-dot">.</span></h1><p class="muted">{{ $business->name }}</p></div>
            </div>
        </header>

        @if(session('ai_provider_notice'))
            <p class="ai-notice {{ session('ai_provider_ok') ? '' : 'ai-error' }}" role="status">{{ session('ai_provider_notice') }}</p>
        @endif
        @if(session('ai_notice'))
            <p class="ai-notice" role="status">{{ session('ai_notice') }}</p>
        @endif
        @if($errors->any())
            <ul class="ai-notice ai-error" role="alert">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif

        <section class="card ai-master">
            <div><h2>AI for this business</h2><p>Turn off to stop new AI helper requests.</p></div>
            <button type="button" role="switch" aria-label="AI for this business" aria-checked="{{ $enabled ? 'true' : 'false' }}"
                class="ai-switch" wire:click="setEnabled({{ $enabled ? 'false' : 'true' }})" wire:loading.attr="disabled">
                {{ $enabled ? 'On' : 'Off' }}
            </button>
        </section>

        <div class="ai-grid">
            <section class="card ai-card">
                <h2>Provider API keys</h2>
                <div class="ai-actions" aria-label="AI providers">
                    @foreach($providers as $id => $details)
                        <button type="button" class="btn {{ $provider === $id ? 'primary' : '' }}" wire:click="$set('provider', '{{ $id }}')"
                            aria-pressed="{{ $provider === $id ? 'true' : 'false' }}">{{ $details['label'] }}</button>
                    @endforeach
                </div>

                <form method="post" action="{{ route('business.ai.provider', [$business, $provider]) }}" wire:key="provider-{{ $provider }}" wire:replace>
                    @csrf
                    <label for="provider-key">{{ $providers[$provider]['label'] }} API key</label>
                    <input id="provider-key" type="password" name="api_key" placeholder="Paste your API key" autocomplete="off" maxlength="512" spellcheck="false">
                    <p class="muted">{{ isset($keySuffixes[$provider]) ? 'Saved key •••• '.$keySuffixes[$provider] : 'No key saved' }} · {{ $providers[$provider]['model'] }}</p>
                    @if($provider === 'groq')
                        <p><a href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">Get a Groq API key <x-g-icon name="arrow-up-right"/></a></p>
                    @endif
                    <div class="ai-actions">
                        <button class="btn primary" type="submit" name="action" value="save">{{ isset($keySuffixes[$provider]) ? 'Replace key' : 'Save key' }}</button>
                        <button class="btn" type="submit" name="action" value="test">Test connection</button>
                        @if(isset($keySuffixes[$provider]))
                            <button class="btn" type="submit" name="action" value="remove" onclick="return confirm('Remove this key and disable its helpers?')">Remove key</button>
                        @endif
                    </div>
                </form>
                <p class="muted">Saving verifies model access. Your first key enables Menu Writing Assistant.</p>
            </section>

            <section class="card ai-card">
                <h2>Monthly usage</h2>
                <p><strong class="ai-cost">US${{ number_format($monthlyCost / 1000000, 2) }}</strong> estimated</p>
                <p>{{ number_format($monthlyRequests) }} requests this month</p>
                <progress aria-label="Monthly estimated usage" max="{{ max(1, (float) $monthlyLimit * 10000) }}" value="{{ $monthlyCost }}"></progress>
                <form wire:submit="saveBudget">
                    <label for="ai-limit">Monthly limit (USD)</label>
                    <input id="ai-limit" type="number" min="0" max="10000" step="0.01" wire:model="monthlyLimit" required>
                    <label class="ai-check"><input type="checkbox" wire:model="pauseAtLimit"> Pause at limit</label>
                    <button class="btn" type="submit" wire:loading.attr="disabled">Save limit</button>
                </form>
                <p class="muted">Usage is estimated from provider token counts. Check your provider for the actual bill.</p>
            </section>
        </div>

        <section class="card ai-card">
            <div class="ai-section-head">
                <h2>Usage by AI helper</h2>
                <select aria-label="Usage period" wire:model.live="period"><option value="current">This month</option><option value="previous">Last month</option></select>
            </div>
            <div class="ai-helper-list">
                @foreach(config('gather-ai.helpers') as $id => $label)
                    <form class="ai-helper" wire:key="helper-{{ $id }}" wire:submit="saveHelper('{{ $id }}')">
                        <strong>{{ $label }}</strong>
                        <label>Provider / model
                            <select wire:model="helpers.{{ $id }}.provider">
                                <option value="">Choose provider</option>
                                @foreach($providers as $key => $details)
                                    @continue($id === 'customer' && $key !== 'groq')
                                    <option value="{{ $key }}">{{ $details['label'] }} · {{ $details['model'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div><span>Requests</span><strong>{{ number_format($usage[$id]->requests ?? 0) }}</strong></div>
                        <div><span>Est. cost (USD)</span><strong>${{ number_format(($usage[$id]->cost ?? 0) / 1000000, 2) }}</strong></div>
                        <label class="ai-check"><input type="checkbox" wire:model="helpers.{{ $id }}.enabled"> {{ $id === 'customer' ? 'Enable customer AI assistant' : 'Enabled' }}</label>
                        <button class="btn" type="submit" wire:loading.attr="disabled">Save</button>
                    </form>
                @endforeach
            </div>
        </section>

        <section class="card ai-card" id="menu-writing">
            <h2>Menu Writing Assistant</h2>
            <label for="ai-product">Menu item</label>
            <select id="ai-product" wire:model.live.number="productId">
                <option value="0">Choose a menu item</option>
                @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
            </select>
            @if($selectedProduct)
                <p><strong>{{ $selectedProduct->name }}</strong> {{ $selectedProduct->description }}</p>
                <form wire:submit="generateDrafts">
                    <label for="ai-source">Source language</label>
                    <select id="ai-source" wire:model="sourceLanguage">
                        @foreach(config('gather-ai.languages') as $locale => $label)<option value="{{ $locale }}">{{ $label }}</option>@endforeach
                    </select>
                    <fieldset>
                        <legend>Target languages</legend>
                        <div class="ai-actions">
                            @foreach(config('gather-ai.languages') as $locale => $label)
                                <label class="ai-check"><input type="checkbox" value="{{ $locale }}" wire:model="languages"> {{ $label }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <button class="btn primary" type="submit" wire:loading.attr="disabled">Generate drafts</button>
                </form>
                @foreach($drafts as $locale => $draft)
                    @if(isset(config('gather-ai.languages')[$locale]))
                        <div class="ai-draft" wire:key="translation-{{ $productId }}-{{ $locale }}">
                            <h3>{{ config('gather-ai.languages')[$locale] }}</h3>
                            <label>Translated name<input wire:model="drafts.{{ $locale }}.name" maxlength="150"></label>
                            <label>Translated description<textarea wire:model="drafts.{{ $locale }}.description" maxlength="1000" rows="3"></textarea></label>
                            <div class="ai-actions">
                                <button class="btn" type="button" wire:click="storeDraft('{{ $locale }}')" wire:loading.attr="disabled">Save draft</button>
                                <button class="btn primary" type="button" wire:click="storeDraft('{{ $locale }}', true)" wire:loading.attr="disabled">Publish translation</button>
                            </div>
                        </div>
                    @endif
                @endforeach
            @endif
        </section>

        <section class="card ai-card">
            <h2>AI Daily Brief and Ask Gather</h2>
            <div class="ai-actions">
                <label>Date<input type="date" wire:model="reportDate"></label>
                <label>Kiosk<select wire:model.number="reportKiosk"><option value="0">All accessible kiosks</option>
                    @foreach($reportKiosks as $kiosk)<option value="{{ $kiosk->id }}">{{ $kiosk->name }}</option>@endforeach
                </select></label>
            </div>
            <button class="btn primary" type="button" wire:click="runReport('brief')" wire:loading.attr="disabled">Generate daily brief</button>
            <form wire:submit="runReport('ask')">
                <label for="ai-question">Ask about orders for this date</label>
                <textarea id="ai-question" wire:model="question" maxlength="500" rows="2" required></textarea>
                <button class="btn" type="submit" wire:loading.attr="disabled">Ask Gather</button>
            </form>
            @if($report !== '')<p class="ai-result" role="status">{{ $report }}</p>@endif
        </section>
    </main>
</div>
