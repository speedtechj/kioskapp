@props(['shift', 'drawerTotals', 'cashCounts'])
@php($money = fn ($cents) => ($cents < 0 ? '−' : '').'₱'.number_format(abs($cents) / 100, 2))
<div class="register-shift-controls" x-data
    @register-open-requested.window="if (!$refs.shiftDialog.open) $refs.shiftDialog.showModal()"
    @register-shift-saved.window="if ($refs.shiftDialog.open) $refs.shiftDialog.close()"
    @register-close-reviewed.window="if (!$refs.shiftDialog.open) $refs.shiftDialog.showModal()">
    <div class="register-shift-actions">
        <span class="badge {{ $shift ? 'paid' : 'unpaid' }}">{{ $shift ? 'Shift open' : 'Shift closed' }}</span>
        @if($shift)
            <button class="btn" type="button" wire:click="reviewRegisterShift" wire:loading.attr="disabled">Close Shift</button>
        @else
            <button class="btn primary" type="button" @click="$refs.shiftDialog.showModal()">Open Shift</button>
        @endif
    </div>
    @if(session('shift_notice'))<p role="status">{{ session('shift_notice') }}</p>@endif
    @error('shift')<p class="error" role="alert">{{ $message }}</p>@enderror
    <dialog class="modal gather-dialog gd-content register-shift-dialog" x-ref="shiftDialog" wire:ignore.self aria-labelledby="register-shift-title">
        <div class="section-line">
            <h2 id="register-shift-title">{{ $shift ? 'Close Shift' : 'Open Shift' }}</h2>
            <button type="button" class="icon-btn" @click="$refs.shiftDialog.close()" aria-label="Dismiss shift dialog"><x-g-icon name="x-mark"/></button>
        </div>
        @if($shift)
            <form wire:submit="closeRegisterShift">
                @if($drawerTotals)
                    <p class="muted">Count cash by denomination. Unused denominations can be left blank.</p>
                    <div class="total-row"><span>Starting cash</span><strong>{{ $money($drawerTotals['opening']) }}</strong></div>
                    <div class="total-row"><span>Cash collected</span><strong>{{ $money($drawerTotals['cash']) }}</strong></div>
                    @if($drawerTotals['refunds'])
                        <div class="total-row"><span>Cash refunds</span><strong>{{ $money($drawerTotals['refunds']) }}</strong></div>
                    @endif
                    <div class="register-cash-count">
                        @foreach(\App\Services\RegisterShifts::DENOMINATIONS as $value => $label)
                            <label class="register-denomination" wire:key="shift-count-{{ $value }}">
                                <span>{{ $label }}</span>
                                <input type="number" inputmode="numeric" min="0" max="99999" step="1" placeholder="0" wire:model.live.debounce.200ms="cashCounts.{{ $value }}">
                                <strong>{{ $money((int) $value * (int) ($cashCounts[$value] ?? 0)) }}</strong>
                            </label>
                        @endforeach
                    </div>
                    @php($counted = \App\Services\RegisterShifts::counted($cashCounts))
                    @php($difference = $counted - $drawerTotals['expected'])
                    <div class="total-row"><span>Counted cash</span><strong>{{ $money($counted) }}</strong></div>
                    <div class="total-row"><span>Expected cash</span><strong>{{ $money($drawerTotals['expected']) }}</strong></div>
                    <p aria-live="polite"><strong>{{ $difference === 0 ? 'Drawer balanced' : $money(abs($difference)).($difference < 0 ? ' short' : ' over') }}</strong></p>
                @endif
                @foreach($errors->get('cashCounts.*') as $messages)
                    @foreach($messages as $message)<p class="error" role="alert">{{ $message }}</p>@endforeach
                @endforeach
                @error('cashCounts')<p class="error" role="alert">{{ $message }}</p>@enderror
                @error('shift')<p class="error" role="alert">{{ $message }}</p>@enderror
                <button class="btn primary wide" type="submit" wire:loading.attr="disabled">Confirm close shift</button>
            </form>
        @else
            <form wire:submit="openRegisterShift">
                <label>Starting cash (PHP)<input type="text" inputmode="decimal" maxlength="10" wire:model="startingCash" required></label>
                @error('startingCash')<p class="error" role="alert">{{ $message }}</p>@enderror
                @error('shift')<p class="error" role="alert">{{ $message }}</p>@enderror
                <button class="btn primary wide" type="submit" wire:loading.attr="disabled">Open Shift</button>
            </form>
        @endif
    </dialog>
</div>
