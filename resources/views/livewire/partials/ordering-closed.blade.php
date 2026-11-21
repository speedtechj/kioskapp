<div class="ordering-availability" role="status">
    @unless($orderingOpen)
        <div class="ordering-closed-notice">
            <x-g-icon name="clock"/>
            <div>
                <strong>Ordering isn’t open yet</strong>
                <p>You can browse the menu now. Please check back shortly to place your order.</p>
            </div>
        </div>
    @endunless
</div>
