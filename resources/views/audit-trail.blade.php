@extends('layouts.app', ['title' => 'Audit Trail'])

@section('content')
    <link rel="stylesheet" href="{{ asset('branding/audit-trail.css').'?v=2' }}">
    <div class="app-shell" x-data="{ navOpen: false, filtersOpen: false }">
        <x-sidebar :business="$business" mode="audit"/>
        <main class="main audit-main">
            <header class="audit-header">
                <h1>Audit Trail</h1>
                <p class="muted">Review activity across your business.</p>
            </header>
            <div class="audit-mobile-filters">
                <button type="button" class="btn audit-filter-toggle" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="audit-filters">
                    Filters <span class="audit-chevron" aria-hidden="true"></span>
                </button>
            </div>
            <form id="audit-filters" class="audit-filters" :class="{ 'is-expanded': filtersOpen }" method="get" action="{{ route('audit.trail', $business) }}">
                <label>Date<select name="date">
                    @foreach($dates as $key => $label)<option value="{{ $key }}" @selected($key === ($filters['date'] ?? 'today'))>{{ $label }}</option>@endforeach
                </select></label>
                <label>Kiosk<select name="kiosk">
                    <option value="">All kiosks</option>
                    @foreach($kiosks as $id => $name)<option value="{{ $id }}" @selected((string) $id === (string) $filters['kiosk'])>{{ $name }}</option>@endforeach
                </select></label>
                <label>Team member<select name="actor">
                    <option value="">All team members</option>
                    @foreach($actors as $actor)<option value="{{ $actor->actor_id }}" @selected((string) $actor->actor_id === (string) $filters['actor'])>{{ $actor->actor_name }}</option>@endforeach
                </select></label>
                <label>Role<select name="role">
                    <option value="">All roles</option>
                    @foreach($roles as $role)<option value="{{ $role }}" @selected($role === $filters['role'])>{{ Str::headline($role) }}</option>@endforeach
                </select></label>
                <label>Action<select name="action">
                    <option value="">All actions</option>
                    @foreach($actions as $action)<option value="{{ $action }}" @selected($action === $filters['action'])>{{ $action }}</option>@endforeach
                </select></label>
                <button class="btn primary audit-apply" type="submit">Apply filters</button>
            </form>
            @if($entries->isEmpty())
                <p class="card audit-empty">No activity matches these filters.</p>
            @else
                <table class="audit-table" role="table" aria-label="Audit activity">
                    <thead><tr><th scope="col">Time</th><th scope="col">Team member</th><th scope="col">Activity</th><th scope="col">Details</th></tr></thead>
                    <tbody role="rowgroup">
                    @foreach($entries as $entry)
                        @php
                            $time = \Illuminate\Support\Carbon::parse($entry->occurred_at);
                            $title = $entry->action.($entry->subject ? ' · '.$entry->subject : '');
                            $details = ['Actor' => $entry->actor_name, 'Role' => Str::headline($entry->role),
                                'Time' => $time->format('M j, Y · g:i A'), 'Kiosk' => $entry->kiosk_name ?? 'Business-wide']
                                + json_decode($entry->details, true, flags: JSON_THROW_ON_ERROR);
                        @endphp
                        <tr x-data role="row">
                            <td class="audit-time" role="cell"><time datetime="{{ $time->toIso8601String() }}">{{ $time->isToday() ? $time->format('g:i A') : $time->format('M j · g:i A') }}</time></td>
                            <td class="audit-actor" role="cell"><strong>{{ $entry->actor_name }}</strong><small>{{ Str::headline($entry->role) }}</small></td>
                            <td class="audit-activity" role="cell"><strong>{{ $title }}</strong>@if($entry->kiosk_name)<small>{{ $entry->kiosk_name }}</small>@endif</td>
                            <td class="audit-actions" role="cell">
                                <button type="button" class="btn audit-view" @click="$refs.details.showModal()" aria-haspopup="dialog" aria-label="View details for {{ $title }}">
                                    View <span class="audit-chevron" aria-hidden="true"></span>
                                </button>
                                <dialog x-ref="details" class="audit-details" aria-labelledby="audit-details-{{ $entry->id }}">
                                    <div class="audit-detail-header">
                                        <h2 id="audit-details-{{ $entry->id }}">Activity details</h2>
                                        <form method="dialog"><button class="icon-btn" autofocus aria-label="Close activity details">✕</button></form>
                                    </div>
                                    <strong class="audit-detail-summary">{{ $title }}</strong>
                                    <dl>
                                        @foreach($details as $key => $value)
                                            <div><dt>{{ Str::headline($key) }}</dt>
                                                <dd>
                                                    @if(is_array($value) && !array_is_list($value))
                                                        <dl>
                                                            @foreach($value as $field => $item)
                                                                <div><dt>{{ Str::headline($field) }}</dt><dd>{{ is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : ($item ?? '—') }}</dd></div>
                                                            @endforeach
                                                        </dl>
                                                    @else
                                                        {{ is_array($value) ? implode(', ', $value) : ($value ?? '—') }}
                                                    @endif
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </dialog>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <nav class="audit-pagination" aria-label="Audit Trail pages">
                    @if($entries->previousPageUrl())<a class="btn" href="{{ $entries->previousPageUrl() }}">Previous</a>@endif
                    @if($entries->nextPageUrl())<a class="btn" href="{{ $entries->nextPageUrl() }}">Next</a>@endif
                </nav>
            @endif
        </main>
    </div>
@endsection
