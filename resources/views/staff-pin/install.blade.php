<dialog class="gather-dialog gd-content cashier-install" id="cashier-install" data-kiosk="{{ $kiosk->id }}"
    @if(request()->routeIs('cashier.sign-in')) data-auto-show @endif aria-labelledby="cashier-install-title">
    <div class="section-line">
        <img width="56" height="56" src="{{ $kiosk->cashierIcon(192) }}" alt="">
        <button type="button" class="icon-btn" aria-label="Close installation guide" data-install-close><x-g-icon name="x-mark"/></button>
    </div>
    <h2 id="cashier-install-title">Install Cashier POS</h2>
    <p>Add quick access to {{ $kiosk->name }} on your Home Screen.</p>
    <div data-install-ios hidden>
        <p>If the QR scanner opened an in-app preview, open the page in Safari first.</p>
        <ol>
            <li>Tap <strong>Share</strong>.</li>
            <li>Tap <strong>Add to Home Screen</strong>.</li>
            <li>Turn on <strong>Open as Web App</strong> if shown, then tap <strong>Add</strong>.</li>
        </ol>
    </div>
    <div data-install-fallback>
        <p>Open your browser menu and choose <strong>Install app</strong> or <strong>Add to Home screen</strong>, if offered.</p>
    </div>
    <button type="button" class="btn primary wide" data-install-confirm hidden>Install Cashier POS</button>
    <button type="button" class="btn wide" data-install-close>Continue in browser</button>
</dialog>
