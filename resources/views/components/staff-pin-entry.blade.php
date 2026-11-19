<div class="pin-entry" x-data="{ pin: '', ready: false }" x-init="ready = true" :class="{ 'pin-entry-ready': ready }"
    @cashier-selected.window="pin = ''">
    <label class="pin-input-label">
        <span>Enter your 6-digit PIN</span>
        <span class="pin-indicator">
            <input class="pin-secret" name="pin" type="password" inputmode="numeric" :inputmode="ready ? 'none' : 'numeric'"
                pattern="[0-9]{6}" maxlength="6" autocomplete="off" x-ref="pin" x-model="pin"
                @input="pin = $event.target.value.replace(/[^0-9]/g, '').slice(0, 6); $event.target.value = pin" required>
            <span class="pin-circles" aria-hidden="true">
                @foreach(range(1, 6) as $position)
                    <span :class="{ 'pin-circle-filled': pin.length >= {{ $position }} }"></span>
                @endforeach
            </span>
        </span>
    </label>
    <div class="pin-keypad" aria-label="PIN keypad">
        @foreach(range(1, 9) as $digit)
            <button type="button" @click="if (pin.length < 6) pin += '{{ $digit }}'">{{ $digit }}</button>
        @endforeach
        <span aria-hidden="true"></span>
        <button type="button" @click="if (pin.length < 6) pin += '0'">0</button>
        <button type="button" aria-label="Delete last digit" @click="pin = pin.slice(0, -1)">⌫</button>
    </div>
</div>
