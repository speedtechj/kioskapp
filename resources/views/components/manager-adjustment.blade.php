@props(['proposal', 'business', 'kiosk'])
<div x-data @adjustment-review.window="if (!$refs.approval.open) $refs.approval.showModal()"
    @adjustment-approved.window="if ($refs.approval.open) $refs.approval.close()">
    <dialog class="gather-dialog gd-content pin-approval" x-ref="approval" @close="if ($refs.approvalPin) $refs.approvalPin.value = ''" wire:ignore.self aria-labelledby="manager-approval-title">
        <div class="section-line">
            <h2 id="manager-approval-title">Manager approval required</h2>
            <button type="button" class="icon-btn" @click="$refs.approval.close()" aria-label="Close approval"><x-g-icon name="x-mark"/></button>
        </div>
        @if($proposal)
            @php($managers = \App\Services\StaffPins::staff($business, $kiosk, ['owner', 'admin', 'manager'], true))
            <form @submit.prevent="let manager = Number($refs.manager.value); let pin = $refs.approvalPin.value; $refs.approvalPin.value = ''; $wire.confirmAdjustment(manager, pin)">
                <section class="pin-preview">
                    <strong>{{ $proposal['label'] }}</strong>
                    <div class="total-row"><span>Current total</span><strong>₱{{ number_format($proposal['before'] / 100, 2) }}</strong></div>
                    <div class="total-row"><span>New total</span><strong>₱{{ number_format($proposal['after'] / 100, 2) }}</strong></div>
                </section>
                <label>Select manager<select x-ref="manager" required>
                    <option value="">Choose a manager</option>
                    @foreach($managers as $manager)<option value="{{ $manager->id }}">{{ $manager->name }}</option>@endforeach
                </select></label>
                @if($managers->isEmpty())<p class="muted">An owner must set up a manager PIN in Cashier access.</p>@endif
                <label>Manager PIN<input x-ref="approvalPin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required></label>
                <div class="pin-keypad pin-approval-keypad" aria-label="Manager PIN keypad">
                    @foreach(range(1, 9) as $digit)
                        <button type="button" @click="if ($refs.approvalPin.value.length < 6) $refs.approvalPin.value += '{{ $digit }}'">{{ $digit }}</button>
                    @endforeach
                    <span></span>
                    <button type="button" @click="if ($refs.approvalPin.value.length < 6) $refs.approvalPin.value += '0'">0</button>
                    <button type="button" aria-label="Delete last digit" @click="$refs.approvalPin.value = $refs.approvalPin.value.slice(0, -1)">⌫</button>
                </div>
                @foreach(['pin', 'managerId', 'adjustment', 'discountValue', 'complimentary', 'shift'] as $field)
                    @error($field)<p role="alert" class="error">{{ $message }}</p>@enderror
                @endforeach
                <button class="btn primary wide" type="submit" wire:loading.attr="disabled" wire:target="confirmAdjustment" @disabled($managers->isEmpty())>Approve adjustment</button>
                <button class="btn" type="button" @click="$refs.approval.close()">Cancel</button>
            </form>
        @endif
    </dialog>
</div>
