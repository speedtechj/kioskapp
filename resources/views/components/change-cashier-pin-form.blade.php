@props(['business', 'kiosk', 'dialog' => false])
            <p>Use 6 digits for your new PIN.</p>
            <form method="post" action="{{ route('cashier.pin.update', [$business, $kiosk]) }}">
                @csrf
                <label>Current PIN<input type="password" name="current_pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="current-password" required></label>
                <label>New PIN<input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required></label>
                <label>Confirm new PIN<input type="password" name="pin_confirmation" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="new-password" required></label>
                @foreach($errors->all() as $error)<p role="alert" class="error">{{ $error }}</p>@endforeach
                <button type="submit" class="btn primary wide">Save new PIN</button>
                @if($dialog)
                    <button type="button" class="btn" @click="$refs.changePin.close()">Cancel</button>
                @else
                    <a class="btn" href="{{ route('pos', [$business, $kiosk]) }}">Cancel</a>
                @endif
            </form>
