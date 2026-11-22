<dialog class="ordering-guide" x-ref="orderingGuide" aria-labelledby="ordering-guide-title" wire:ignore>
    <div class="ordering-guide-top">
        <h2 id="ordering-guide-title">Ordering guide / <span lang="tl">Gabay sa pag-order</span></h2>
        <form method="dialog">
            <button type="submit" class="icon-btn" aria-label="Close guide / Isara ang gabay" autofocus>×</button>
        </form>
    </div>
    <ol>
        <li>
            <h3>Add an item / <span lang="tl">Magdagdag ng item</span></h3>
            <p>Tap a photo or +, choose a quantity, then tap Add to cart.</p>
            <p lang="tl">I-tap ang larawan o +, piliin ang dami, saka i-tap ang Add to cart.</p>
            <div class="ordering-guide-example" aria-hidden="true">
                <img src="{{ $products->first()?->image ?? asset('images/bowl.jpg') }}" alt="" loading="lazy">
                <strong class="ordering-guide-action">+ &nbsp; Add to cart</strong>
            </div>
        </li>
        <li>
            <h3>View your cart / <span lang="tl">Tingnan ang cart</span></h3>
            <p>Tap View cart to review your items. Back to menu keeps your cart saved.</p>
            <p lang="tl">I-tap ang View cart para suriin ang mga item. Nananatili ang laman ng cart kapag bumalik ka sa menu.</p>
            <div class="ordering-guide-example" aria-hidden="true"><strong class="ordering-guide-action">View cart · 1 item</strong></div>
        </li>
        <li>
            <h3>Optional toppings / <span lang="tl">Opsyonal na toppings</span></h3>
            <p>In your cart, use + or − to choose toppings. Leave the quantity at 0 to skip.</p>
            <p lang="tl">Sa cart, gamitin ang + o − para pumili ng toppings. Iwan sa 0 kung ayaw mong magdagdag.</p>
            <div class="ordering-guide-example ordering-guide-list">
                <div><span>Egg / <span lang="tl">Itlog</span> · ₱10 per piece</span><strong aria-hidden="true">− &nbsp; 0 &nbsp; +</strong></div>
                <div><span>Chili sauce · Free / <span lang="tl">Libre</span></span><strong aria-hidden="true">− &nbsp; 0 &nbsp; +</strong></div>
            </div>
        </li>
        <li>
            <h3>Remove an item / <span lang="tl">Alisin ang item</span></h3>
            <p>Before placing your order, tap − until the item quantity reaches 0.</p>
            <p lang="tl">Bago ipadala ang order, i-tap ang − hanggang maging 0 ang dami ng item.</p>
            <div class="ordering-guide-example" aria-hidden="true"><strong>− &nbsp; 1 → 0</strong></div>
        </li>
        <li>
            <h3>Place your order / <span lang="tl">Ipadala ang order</span></h3>
            <p>Review the total, tap Place order, then pay at the counter.</p>
            <p lang="tl">Suriin ang kabuuan, i-tap ang Place order, saka magbayad sa counter.</p>
            <div class="ordering-guide-example" aria-hidden="true"><strong class="ordering-guide-action">Place order · Pay at counter</strong></div>
        </li>
        <li>
            <h3>Track your order / <span lang="tl">Subaybayan ang order</span></h3>
            <p>Open Track my order on the menu and tap Refresh status for an update.</p>
            <p lang="tl">Buksan ang Track my order sa menu at i-tap ang Refresh status para makita ang bagong status.</p>
            <div class="ordering-guide-example ordering-guide-list">
                <div>Received / <span lang="tl">Natanggap</span></div>
                <div>Preparing / <span lang="tl">Inihahanda</span></div>
                <div>Ready / <span lang="tl">Handa nang kunin</span></div>
                <div>Served / <span lang="tl">Naibigay na</span></div>
            </div>
        </li>
        <li>
            <h3>Order already placed? / <span lang="tl">Naipadala na ang order?</span></h3>
            <p>To buy more items, return to the menu and place a separate order. Ask the counter to change or remove items from an order you already sent.</p>
            <p lang="tl">Para bumili pa, bumalik sa menu at gumawa ng hiwalay na order. Magtanong sa counter kung may gustong baguhin o alisin sa naipadala nang order.</p>
        </li>
    </ol>
    <form method="dialog" class="ordering-guide-bottom">
        <button type="submit" class="btn wide">Close guide / <span lang="tl">Isara ang gabay</span></button>
    </form>
</dialog>
