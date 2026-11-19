@props(['field', 'label', 'url', 'upload' => null, 'hint' => 'PNG, JPEG or WebP · up to 3 MB'])
<div class="control-field">
    <label for="{{ $field }}">{{ $label }}</label>
    <div class="control-upload">
        <img src="{{ $upload instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && $upload->isPreviewable() ? $upload->temporaryUrl() : $url }}" alt="">
        <div>
            <input type="file" id="{{ $field }}" wire:model="{{ $field }}" accept="image/png,image/jpeg,image/webp">
            <small>{{ $hint }}</small>
            <small wire:loading wire:target="{{ $field }}">Uploading…</small>
        </div>
    </div>
    @error($field)<p class="control-error">{{ $message }}</p>@enderror
</div>
