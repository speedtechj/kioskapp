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
            <table>
                <thead><tr><th scope="col">Item</th><th scope="col">Regular</th><th scope="col">Save</th><th scope="col">{{ $active ? 'Now' : 'Promo price' }}</th></tr></thead>
                <tbody>
                    @foreach($items as $item)
                        @php($saving = $promotion->unitSaving($item->price))
                        <tr>
                            <th scope="row">{{ $item->menuText('name', $attributes->get('language', '')) }}</th>
                            <td>₱{{ number_format($item->price / 100, 2) }}</td>
                            <td>₱{{ number_format($saving / 100, 2) }}</td>
                            <td><strong>₱{{ number_format(($item->price - $saving) / 100, 2) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if($active)<p>Applied automatically at checkout.</p>@endif
            <form method="dialog"><button class="btn wide">Back to menu</button></form>
        </dialog>
    </section>
@endif
