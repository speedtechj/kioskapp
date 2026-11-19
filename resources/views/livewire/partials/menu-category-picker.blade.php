@assets
<link rel="stylesheet" href="{{ asset('branding/menu-category-picker.css') }}">
@endassets

<div class="menu-category-picker"
    x-data="{ expanded: false, search: '', added: '', saving: false }"
    @menu-category-added.window="added = $event.detail.name; $refs.categoryDialog.close()">
    <div class="menu-category-row">
        <div class="menu-category-field" @click.outside="expanded = false"
            @keydown.escape.stop.prevent="if (expanded) { expanded = false; $refs.categoryToggle.focus() }">
            <label id="product-category-label" for="product-category-toggle">Category</label>
            <button type="button" id="product-category-toggle" class="btn menu-category-toggle"
                x-ref="categoryToggle" aria-labelledby="product-category-label product-category-value"
                aria-controls="product-category-options" :aria-expanded="expanded"
                @click="expanded = !expanded; search = ''; if (expanded) $nextTick(() => $refs.categorySearch.focus())">
                <span id="product-category-value" x-text="$wire.form.category || 'Select category'"></span>
                <span aria-hidden="true">⌄</span>
            </button>
            <div id="product-category-options" class="menu-category-options" x-show="expanded" x-cloak>
                <input type="search" aria-label="Search categories" placeholder="Search categories"
                    x-model="search" x-ref="categorySearch" @keydown.enter.prevent>
                <ul>
                    @foreach($categories as $category)
                        <li wire:key="category-{{ sha1($category) }}"
                            x-show="@js($category).toLowerCase().includes(search.trim().toLowerCase())">
                            <button type="button"
                                @click="$wire.$set('form.category', @js($category), false); added = ''; expanded = false; $refs.categoryToggle.focus()">
                                {{ $category }}
                            </button>
                        </li>
                    @endforeach
                </ul>
                <p x-show="!@js($categories).some(name => name.toLowerCase().includes(search.trim().toLowerCase()))">No matching categories.</p>
            </div>
        </div>
        <button type="button" class="btn primary menu-category-add" aria-label="Add category" title="Add category"
            @click="expanded = false; $refs.categoryDialog.showModal(); $wire.resetMenuCategoryInput()">+</button>
        <label class="menu-category-price">Price (PHP)
            <input type="number" min="0.01" step="0.01" wire:model="form.price" required>
        </label>
    </div>
    <p class="menu-category-status" role="status" x-show="added" x-cloak>✓ <span x-text="added + ' added'"></span></p>

    <dialog wire:ignore.self x-ref="categoryDialog" class="menu-category-dialog" aria-labelledby="add-category-title"
        @cancel.stop.prevent="$refs.categoryDialog.close()">
        <div class="section-line">
            <h3 id="add-category-title">Add category</h3>
            <button type="button" class="icon-btn" aria-label="Close Add category" @click="$refs.categoryDialog.close()"><x-g-icon name="x-mark"/></button>
        </div>
        <label>Category name
            <input wire:model="newCategoryName" maxlength="50" autofocus
                @keydown.enter.stop.prevent="if (!saving) { saving = true; $wire.addMenuCategory().finally(() => saving = false) }">
        </label>
        @error('newCategoryName')<p class="error" role="alert">{{ $message }}</p>@enderror
        <div class="modal-actions">
            <button type="button" class="btn" @click="$refs.categoryDialog.close()">Cancel</button>
            <button type="button" class="btn primary" :disabled="saving"
                @click="saving = true; $wire.addMenuCategory().finally(() => saving = false)">Add category</button>
        </div>
    </dialog>
</div>
