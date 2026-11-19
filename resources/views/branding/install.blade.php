@if($kiosk)
<button class="install-reopen" id="install-reopen" type="button" hidden>Save app</button>
@endif
<dialog id="brand-install" class="gather-dialog install-sheet" aria-labelledby="install-title"
    data-key="{{ $kiosk?->id ?? 'staff' }}" @if(!$kiosk) data-staff @endif>
    <button type="button" class="install-close" data-close-install aria-label="Close install guide">
        <x-g-icon name="x-mark"/>
    </button>
    <img width="56" height="56" src="{{ $kiosk?->appIcon(192) ?? asset('branding/bowl-192.png') }}" alt="">
    <h2 id="install-title" data-android-title="Install {{ $kiosk?->appLabel() ?? 'Gather Staff' }}">Add to Home Screen</h2>
    <div id="ios-help" hidden>
        <p class="install-device">iPhone · Safari</p>
        <ol>
            <li>Open the link in Safari and tap <strong>Share</strong>.</li>
            <li>Choose <strong>Add to Home Screen</strong>, turn on <strong>Open as Web App</strong> if shown, then tap <strong>Add</strong>.</li>
        </ol>
    </div>
    <div id="android-help" hidden>
        <p class="install-device">Android · Chrome</p>
        <p>Open the app from your Home Screen.</p>
    </div>
    <button type="button" class="btn primary wide" id="do-install" hidden>Install app</button>
    <button type="button" class="btn wide" id="skip-install" data-close-install>{{ $kiosk ? 'Continue to menu' : 'Continue to workspace' }}</button>
    <p class="install-fallback" id="android-fallback" hidden>In Chrome, open <strong>⋮</strong> and choose <strong>Install app</strong> or <strong>Add to Home screen</strong> if offered.</p>
</dialog>
