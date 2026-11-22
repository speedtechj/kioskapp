<dl class="promo-schedule">
    @foreach(['announces_at' => 'Announcement', 'starts_at' => 'Discount starts', 'ends_at' => 'Discount ends'] as $field => $label)
        <div><dt>{{ $label }}</dt><dd><time datetime="{{ $promotion->{$field}->toIso8601String() }}">{{ $promotion->{$field}->format('D, M j, Y · g:i A') }}</time></dd></div>
    @endforeach
</dl>
