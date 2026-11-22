@props(['promotion', 'items', 'phase', 'staff' => false])

@if($phase)
    @php
        $active = $phase === 'active';
        $schedule = $active ? 'Ends '.$promotion->ends_at->format('D, M j \a\t g:i A') : 'Starts '.$promotion->starts_at->format('D, M j \a\t g:i A');
        $headline = $promotion->percent.'% off '.($promotion->all_items ? 'all menu items' : $items->count().' selected '.\Illuminate\Support\Str::plural('item', $items->count()));
    @endphp
    <section class="menu-promo-banner menu-promo-{{ $phase }}" x-data>
        <strong>{{ $active ? ($staff ? 'Promo active' : 'Active now') : ($staff ? 'Upcoming promo' : 'Coming soon') }}</strong>
        <span>{{ $headline }}</span>
        <small>{{ $schedule }}</small>
        <button type="button" class="text-link" aria-haspopup="dialog" x-on:click="$refs.promoDetails.showModal()">See eligible items</button>
        <dialog x-ref="promoDetails" class="menu-promo-dialog" aria-label="{{ $promotion->name }}">
            <form method="dialog" class="menu-promo-close"><button class="icon-btn" aria-label="Close promotion">×</button></form>
            <h2>{{ $promotion->name }}</h2>
            <p><strong>{{ $headline }}</strong></p>
            <p>{{ $schedule }}</p>
            @unless($active)<p>Ends {{ $promotion->ends_at->format('D, M j \a\t g:i A') }}. Discount applies when the promo starts.</p>@endunless
            <h3>Eligible items ({{ $items->count() }})</h3>
            <ul class="menu-promo-items">
                @foreach($items as $item)
                    @php($saving = $promotion->unitSaving($item->price))
                    <li class="menu-promo-item">
                        <strong>{{ $item->menuText('name', $attributes->get('language', '')) }}</strong>
                        <div>Regular <span>₱{{ number_format($item->price / 100, 2) }}</span></div>
                        <div class="menu-promo-saving">Save <span>₱{{ number_format($saving / 100, 2) }}</span></div>
                        <div class="menu-promo-final">{{ $active ? 'Now' : 'Promo price' }} <span>₱{{ number_format(($item->price - $saving) / 100, 2) }}</span></div>
                    </li>
                @endforeach
            </ul>
            @if($active)<p>Applied automatically at checkout.</p>@endif
            <form method="dialog"><button class="btn wide">Back to menu</button></form>
        </dialog>
    </section>
@endif
