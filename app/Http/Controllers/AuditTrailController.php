<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\BusinessAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuditTrailController
{
    public function __invoke(Request $request, Business $business)
    {
        abort_unless(BusinessAudit::canView($request->user(), $business->id), 403);
        $filters = validator($request->query(), [
            'date' => ['nullable', Rule::in(['today', 'week', 'month', 'all'])],
            'kiosk' => 'nullable|integer|min:1',
            'actor' => 'nullable|integer|min:1',
            'role' => 'nullable|string|max:32',
            'action' => 'nullable|string|max:64',
        ])->validate();
        $filters += ['date' => 'today', 'kiosk' => null, 'actor' => null, 'role' => null, 'action' => null];

        $events = DB::table('business_audit_events')->where('business_id', $business->id);
        $actors = (clone $events)->select('actor_id')->selectRaw('MAX(actor_name) as actor_name')->groupBy('actor_id')->orderBy('actor_name')->get();
        $kiosks = $business->kiosks()->orderBy('name')->pluck('name', 'id');
        $kiosks = $kiosks->union((clone $events)->whereNotNull('kiosk_name')->select('kiosk_id')
            ->selectRaw('MAX(kiosk_name) as kiosk_name')->groupBy('kiosk_id')->pluck('kiosk_name', 'kiosk_id'));
        $roles = (clone $events)->distinct()->orderBy('role')->pluck('role');
        $actions = (clone $events)->distinct()->orderBy('action')->pluck('action');
        $dates = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'all' => 'All dates'];
        $start = match ($filters['date']) {
            'week' => today()->subDays(6),
            'month' => today()->subDays(29),
            'all' => null,
            default => today(),
        };
        $events->when($start, fn ($query) => $query->where('occurred_at', '>=', $start));
        foreach (['kiosk' => 'kiosk_id', 'actor' => 'actor_id', 'role' => 'role', 'action' => 'action'] as $filter => $column) {
            $events->when($filters[$filter], fn ($query, $value) => $query->where($column, $value));
        }
        $entries = $events->orderByDesc('occurred_at')->orderByDesc('id')->simplePaginate(25)->appends($filters);

        return response()->view('audit-trail', compact('business', 'entries', 'filters', 'actors', 'kiosks', 'roles', 'actions', 'dates'))
            ->header('Cache-Control', 'private, no-store');
    }
}
