@props(['field', 'label', 'type' => 'text', 'live' => false, 'required' => true, 'autocomplete' => null])
<div class="control-field">
    <label for="{{ $field }}">{{ $label }}</label>
    <x-filament::input.wrapper>
        @if($live)
            <x-filament::input :id="$field" wire:model.live.debounce.300ms="{{ $field }}" :type="$type" :required="$required"/>
        @else
            <x-filament::input :id="$field" wire:model="{{ $field }}" :type="$type" :required="$required" :autocomplete="$autocomplete"/>
        @endif
    </x-filament::input.wrapper>
    @error($field)<p class="control-error">{{ $message }}</p>@enderror
</div>
