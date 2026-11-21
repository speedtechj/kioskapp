#!/usr/bin/env bash
set -euo pipefail

if [[ $# -gt 1 || (${1:-} != "" && ${1:-} != "--check") ]]; then
    echo "Usage: bash orderlane-category-removal.sh [--check]" >&2
    exit 2
fi

if [[ ! -f artisan || ! -f resources/views/livewire/partials/menu-category-picker.blade.php ]]; then
    echo "Run this from the Orderlane Laravel project folder with the category picker installed." >&2
    exit 1
fi

patch_file=$(mktemp)
trap 'rm -f "$patch_file"' EXIT
cat > "$patch_file" <<'ORDERLANE_PATCH'
--- a/app/Livewire/Workspace.php
+++ b/app/Livewire/Workspace.php
@@ -149,22 +149,77 @@
             }
         }]]);
 
-        $normalized = Str::lower($this->newCategoryName);
-        $existing = collect($this->menuCategories())->first(fn (string $name) => Str::lower(trim($name)) === $normalized);
-        $nameKey = hash('sha256', $normalized);
-        $inserted = DB::table('menu_categories')->insertOrIgnore([
-            'business_id' => $this->businessId,
-            'name_key' => $nameKey,
-            'name' => $existing ?? $this->newCategoryName,
-        ]);
-
-        $this->form['category'] = DB::table('menu_categories')->where('business_id', $this->businessId)
-            ->where('name_key', $nameKey)->value('name');
-        if ($inserted) {
-            BusinessAudit::record($this->businessId, 'Menu category created', $this->form['category']);
-        }
+        DB::transaction(function () {
+            Business::query()->lockForUpdate()->findOrFail($this->businessId);
+            $normalized = Str::lower($this->newCategoryName);
+            $existing = collect($this->menuCategories())->first(fn (string $name) => Str::lower(trim($name)) === $normalized);
+            $nameKey = hash('sha256', $normalized);
+            $inserted = DB::table('menu_categories')->insertOrIgnore([
+                'business_id' => $this->businessId,
+                'name_key' => $nameKey,
+                'name' => $existing ?? $this->newCategoryName,
+            ]);
+
+            $this->form['category'] = DB::table('menu_categories')->where('business_id', $this->businessId)
+                ->where('name_key', $nameKey)->value('name');
+            if ($inserted) {
+                BusinessAudit::record($this->businessId, 'Menu category created', $this->form['category']);
+            }
+        });
         $this->reset('newCategoryName');
         $this->dispatch('menu-category-added', name: $this->form['category']);
+    }
+
+    public function removeMenuCategory(string $category, string $replacement = ''): void
+    {
+        Access::authorize($this->businessId, 'menu.manage');
+        abort_unless($this->modal && $this->formType === 'product', 403);
+
+        $this->resetValidation('categoryRemoval');
+
+        DB::transaction(function () use ($category, $replacement) {
+            Business::query()->lockForUpdate()->findOrFail($this->businessId);
+            $categories = $this->menuCategories();
+            if (! in_array($category, $categories, true)) {
+                throw ValidationException::withMessages(['categoryRemoval' => 'This category is no longer available.']);
+            }
+
+            $key = Str::lower(trim($category));
+            $products = Product::where('business_id', $this->businessId);
+            $productIds = (clone $products)->pluck('category', 'id')
+                ->filter(fn (?string $name) => $name !== null && Str::lower(trim($name)) === $key)->keys();
+
+            if ($productIds->isNotEmpty()) {
+                if ($replacement === $category || ! in_array($replacement, $categories, true)) {
+                    throw ValidationException::withMessages(['categoryRemoval' => 'Choose another category to keep these products on the menu.']);
+                }
+
+                $products->whereKey($productIds->all())->update(['category' => $replacement]);
+            }
+
+            DB::table('menu_categories')->where('business_id', $this->businessId)
+                ->where('name_key', hash('sha256', $key))->delete();
+        });
+
+        if (Str::lower(trim($this->form['category'] ?? '')) === Str::lower(trim($category))) {
+            $this->form['category'] = $replacement !== $category && in_array($replacement, $this->menuCategories(), true)
+                ? $replacement : '';
+        }
+
+        $this->dispatch('menu-category-removed', name: $category);
+    }
+
+    public function resetValidationForCategoryRemoval(): void
+    {
+        $this->resetValidation('categoryRemoval');
+    }
+
+    public function menuCategoryProductCounts(): array
+    {
+        Access::authorize($this->businessId, 'menu.manage');
+
+        return Product::where('business_id', $this->businessId)->whereNotNull('category')->pluck('category')
+            ->countBy(fn (string $name) => Str::lower(trim($name)))->all();
     }
 
     public function resetMenuCategoryInput(): void
@@ -275,6 +330,11 @@
                     $data['image'] = '/storage/'.$storedPath;
                 }
                 DB::transaction(function () use ($product, $data) {
+                    Business::query()->lockForUpdate()->findOrFail($this->businessId);
+                    if (! collect($this->menuCategories())->contains(fn (string $name) => Str::lower(trim($name)) === Str::lower(trim($data['category'])))) {
+                        throw ValidationException::withMessages(['form.category' => 'Select an available category.']);
+                    }
+
                     $product->visible_kiosk_ids = $this->allMenuKiosks ? null : array_map('intval',array_values($this->menuKiosks));
                     $product->fill($data)->save();
                     foreach (Kiosk::where('business_id', $this->businessId)->get() as $kiosk) {
--- a/resources/views/livewire/partials/menu-category-picker.blade.php
+++ b/resources/views/livewire/partials/menu-category-picker.blade.php
@@ -3,7 +3,8 @@
 @endassets
 
 <div class="menu-category-picker"
-    x-data="{ expanded: false, search: '', added: '', saving: false }"
+    x-data="{ expanded: false, search: '', added: '', removed: '', saving: false, selected: '', replacement: '', counts: {} }"
+    @menu-category-removed.window="removed = $event.detail.name; $refs.manageDialog.close(); $refs.categoryToggle.focus()"
     @menu-category-added.window="added = $event.detail.name; $refs.categoryDialog.close()">
     <div class="menu-category-row">
         <div class="menu-category-field" @click.outside="expanded = false"
@@ -31,6 +32,10 @@
                     @endforeach
                 </ul>
                 <p x-show="!@js($categories).some(name => name.toLowerCase().includes(search.trim().toLowerCase()))">No matching categories.</p>
+                <button type="button" class="btn menu-category-manage"
+                    @click="expanded = false; selected = ''; replacement = ''; removed = ''; added = ''; counts = @js($this->menuCategoryProductCounts()); $refs.manageDialog.showModal()">
+                    Manage categories
+                </button>
             </div>
         </div>
         <button type="button" class="btn primary menu-category-add" aria-label="Add category" title="Add category"
@@ -39,6 +44,8 @@
             <input type="number" min="0.01" step="0.01" wire:model="form.price" required>
         </label>
     </div>
+    @error('form.category')<p class="error" role="alert">{{ $message }}</p>@enderror
+    <p class="menu-category-status" role="status" x-show="removed" x-cloak x-text="removed + ' removed'"></p>
     <p class="menu-category-status" role="status" x-show="added" x-cloak>✓ <span x-text="added + ' added'"></span></p>
 
     <dialog wire:ignore.self x-ref="categoryDialog" class="menu-category-dialog" aria-labelledby="add-category-title"
@@ -58,4 +65,44 @@
                 @click="saving = true; $wire.addMenuCategory().finally(() => saving = false)">Add category</button>
         </div>
     </dialog>
+
+    <dialog wire:ignore.self x-ref="manageDialog" class="menu-category-dialog" aria-labelledby="manage-category-title"
+        @cancel.stop.prevent="if (!saving) { $refs.manageDialog.close(); $refs.categoryToggle.focus() }">
+        <div class="section-line">
+            <h3 id="manage-category-title" x-text="selected ? 'Remove ' + selected + ' category?' : 'Manage categories'"></h3>
+        </div>
+        <div x-show="!selected">
+            <ul class="menu-category-list">
+                @foreach($categories as $category)
+                    <li wire:key="manage-category-{{ sha1($category) }}">
+                        <span>{{ $category }}</span>
+                        <button type="button" class="btn" aria-label="Remove {{ $category }}"
+                            @click="selected = @js($category); replacement = ''; $wire.resetValidationForCategoryRemoval()">Remove</button>
+                    </li>
+                @endforeach
+            </ul>
+            @if($categories === [])<p>No categories to manage.</p>@endif
+        </div>
+        <div x-show="selected" x-cloak>
+            <p x-show="counts[selected.trim().toLowerCase()] > 0">Move the products in this category to keep them on the menu.</p>
+            <label x-show="counts[selected.trim().toLowerCase()] > 0">Move products to
+                <select x-model="replacement">
+                    <option value="">Select a category</option>
+                    @foreach($categories as $category)
+                        <option value="{{ $category }}" :disabled="selected === @js($category)">{{ $category }}</option>
+                    @endforeach
+                </select>
+            </label>
+            @error('categoryRemoval')<p class="error" role="alert">{{ $message }}</p>@enderror
+        </div>
+        <div class="modal-actions">
+            <button type="button" class="btn" :disabled="saving"
+                @click="$refs.manageDialog.close(); $refs.categoryToggle.focus()" x-text="selected ? 'Cancel' : 'Done'"></button>
+            <button type="button" class="btn menu-category-remove" x-show="selected" x-cloak
+                :disabled="saving || (counts[selected.trim().toLowerCase()] > 0 && !replacement)"
+                @click="saving = true; $wire.removeMenuCategory(selected, replacement).finally(() => saving = false)">
+                Remove category
+            </button>
+        </div>
+    </dialog>
 </div>
--- a/public/branding/menu-category-picker.css
+++ b/public/branding/menu-category-picker.css
@@ -47,3 +47,17 @@
     .menu-category-row { grid-template-columns: minmax(0, 1fr) 44px; }
     .menu-category-price { grid-column: 1 / -1; }
 }
+
+.menu-category-options .menu-category-manage { width: 100%; justify-content: center; margin-top: 8px; }
+.menu-category-list { list-style: none; margin: 12px 0; padding: 0; }
+.menu-category-list li {
+    display: flex;
+    align-items: center;
+    justify-content: space-between;
+    gap: 12px;
+    padding: 8px 0;
+    border-bottom: 1px solid var(--line);
+}
+.menu-category-list li span { overflow-wrap: anywhere; }
+.menu-category-dialog .menu-category-remove { background: #a52720; border-color: #a52720; color: white; }
+.menu-category-dialog .menu-category-remove:disabled { opacity: .55; }
ORDERLANE_PATCH

if git apply --reverse --check "$patch_file" >/dev/null 2>&1; then
    echo "Category removal is already installed."
    exit 0
fi

if ! git apply --check "$patch_file"; then
    echo "Your category picker differs from the tested version. No files changed." >&2
    exit 1
fi

if [[ ${1:-} == "--check" ]]; then
    echo "Category removal compatibility check passed. No files changed."
    exit 0
fi

git apply "$patch_file"
echo "Category removal installed. Run php artisan view:clear."
