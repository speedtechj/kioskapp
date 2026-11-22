<div class="customer-menu-heading" x-data>
    <div class="customer-menu-heading-label">
        <h1>Menu</h1>
        <button type="button" class="customer-menu-help" aria-label="Ordering guide / Gabay sa pag-order"
            aria-haspopup="dialog" x-on:click="$refs.orderingGuide.showModal()">?</button>
    </div>
    @include('livewire.partials.ordering-guide')

    @if($askJoyEnabled)
        @include('livewire.partials.ask-joy')
    @endif
</div>
