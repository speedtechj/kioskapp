<section class="card">
    <div class="section-line">
        <h2>Toppings</h2>
        <button type="button" class="btn primary" wire:click="editTopping">+ Add topping</button>
    </div>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Topping</th><th>Price per piece (PHP)</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                @forelse($toppings as $topping)
                    <tr wire:key="topping-admin-{{ $topping->id }}">
                        <td>{{ $topping->name }}</td>
                        <td>₱{{ number_format($topping->price / 100, 2) }}</td>
                        <td>{{ $topping->active ? 'Active' : 'Inactive' }}</td>
                        <td><button type="button" class="btn" wire:click="editTopping({{ $topping->id }})">Edit</button></td>
                    </tr>
                @empty
                    <tr><td colspan="4">No toppings yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($toppingEditorOpen)
        <form wire:submit="saveTopping" class="card">
            <h3>{{ $toppingId ? 'Edit topping' : 'Add topping' }}</h3>
            <label>Topping name <input wire:model="toppingForm.name" maxlength="100" required></label>
            @error('toppingForm.name')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label>Price per piece (PHP) <input wire:model="toppingForm.price" type="number" min="0.01" step="0.01" required></label>
            @error('toppingForm.price')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="check-label"><input type="checkbox" wire:model="toppingForm.active"> Active</label>
            <button type="button" class="btn" wire:click="$set('toppingEditorOpen', false)">Cancel</button>
            <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="saveTopping">Save topping</button>
        </form>
    @endif
</section>
