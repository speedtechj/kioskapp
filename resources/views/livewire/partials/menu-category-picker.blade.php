@assets
<link rel="stylesheet" href="{{ asset('branding/menu-category-picker.css') }}">
@endassets

<div class="menu-category-picker"
    x-data="{ expanded: false, search: '', added: '', removed: '', saving: false, selected: '', replacement: '', counts: {} }"
    @menu-category-removed.window="removed = $event.detail.name; $refs.manageDialog.close(); $refs.categoryToggle.focus()"
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
                <button type="button" class="btn menu-category-manage"
                    @click="expanded = false; selected = ''; replacement = ''; removed = ''; added = ''; counts = @js($this->menuCategoryProductCounts()); $refs.manageDialog.showModal()">
                    Manage categories
                </button>
            </div>
        </div>
        <button type="button" class="btn primary menu-category-add" aria-label="Add category" title="Add category"
            @click="expanded = false; $refs.categoryDialog.showModal(); $wire.resetMenuCategoryInput()">+</button>
        <label class="menu-category-price">Price (PHP)
            <input type="number" min="0.01" step="0.01" wire:model="form.price" required>
        </label>
    </div>
    @error('form.category')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p class="menu-category-status" role="status" x-show="removed" x-cloak x-text="removed + ' removed'"></p>
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

    <dialog wire:ignore.self x-ref="manageDialog" class="menu-category-dialog" aria-labelledby="manage-category-title"
        @cancel.stop.prevent="if (!saving) { $refs.manageDialog.close(); $refs.categoryToggle.focus() }">
        <div class="section-line">
            <h3 id="manage-category-title" x-text="selected ? 'Remove ' + selected + ' category?' : 'Manage categories'"></h3>
        </div>
        <div x-show="!selected">
            <ul class="menu-category-list">
                @foreach($categories as $category)
                    <li wire:key="manage-category-{{ sha1($category) }}">
                        <span>{{ $category }}</span>
                        <button type="button" class="btn" aria-label="Remove {{ $category }}"
                            @click="selected = @js($category); replacement = ''; $wire.resetValidationForCategoryRemoval()">Remove</button>
                    </li>
                @endforeach
            </ul>
            @if($categories === [])<p>No categories to manage.</p>@endif
        </div>
        <div x-show="selected" x-cloak>
            <p x-show="counts[selected.trim().toLowerCase()] > 0">Move the products in this category to keep them on the menu.</p>
            <label x-show="counts[selected.trim().toLowerCase()] > 0">Move products to
                <select x-model="replacement">
                    <option value="">Select a category</option>
                    @foreach($categories as $category)
                        <option value="{{ $category }}" :disabled="selected === @js($category)">{{ $category }}</option>
                    @endforeach
                </select>
            </label>
            @error('categoryRemoval')<p class="error" role="alert">{{ $message }}</p>@enderror
        </div>
        <div class="modal-actions">
            <button type="button" class="btn" :disabled="saving"
                @click="$refs.manageDialog.close(); $refs.categoryToggle.focus()" x-text="selected ? 'Cancel' : 'Done'"></button>
            <button type="button" class="btn menu-category-remove" x-show="selected" x-cloak
                :disabled="saving || (counts[selected.trim().toLowerCase()] > 0 && !replacement)"
                @click="saving = true; $wire.removeMenuCategory(selected, replacement).finally(() => saving = false)">
                Remove category
            </button>
        </div>
    </dialog>
</div>
