<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\Access;
use App\Services\SalesSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesSummaryController
{
    public function __invoke(Request $request, Business $business, SalesSummary $summary)
    {
        Access::authorize($business->id, 'reports.view');
        $kiosks = Access::kiosks($request->user(), $business->id)->sortBy('name')->pluck('name', 'id');
        $periods = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'custom' => 'Custom dates'];
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(array_keys($periods))],
            'kiosk' => 'nullable|integer|min:1',
            'start' => 'exclude_unless:period,custom|required|date_format:Y-m-d',
            'end' => 'exclude_unless:period,custom|required|date_format:Y-m-d|after_or_equal:start',
            'format' => 'nullable|in:csv',
        ]);
        $period = $filters['period'] ?? 'week';
        $kiosk = isset($filters['kiosk']) ? (int) $filters['kiosk'] : null;
        abort_if($kiosk !== null && ! $kiosks->has($kiosk), 403);

        $today = today();
        [$start, $end] = match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'month' => [$today->copy()->startOfMonth(), $today],
            'custom' => [Carbon::parse($filters['start']), Carbon::parse($filters['end'])],
            default => [$today->copy()->startOfWeek(), $today],
        };
        if ($start->diffInDays($end) >= 366) {
            throw ValidationException::withMessages(['end' => 'Choose a range of up to 366 days.']);
        }

        $report = $summary->report($business->id, $kiosk === null ? $kiosks->keys()->all() : [$kiosk], $start, $end);
        if (($filters['format'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($report, $kiosks) {
                $stream = fopen('php://output', 'w');
                $write = fn (array $row) => fputcsv($stream, $row, escape: '');
                $write(['Section', 'Label', 'Gross sales', 'Discounts', 'Refunds', 'Net sales', 'Paid orders', 'Payments collected']);
                foreach (['Total' => ['Total' => $report['totals']], 'Daily' => $report['days'], 'Kiosk' => $report['kiosks']] as $section => $rows) {
                    foreach ($rows as $key => $row) {
                        $label = $section === 'Kiosk' ? $kiosks[$key] : $key;
                        if (preg_match('/^[\s]*[=+@-]|^[\t\r\n]/', (string) $label)) {
                            $label = "'".$label;
                        }
                        $write([$section, $label, ...array_map(fn ($value) => number_format($value / 100, 2, '.', ''), array_slice($row, 0, 4)), $row['paid_orders'], $section === 'Total' ? number_format($report['methods']->sum() / 100, 2, '.', '') : '']);
                    }
                }
                foreach ($report['methods'] as $method => $amount) {
                    $write(['Payment method', self::paymentLabel($method), '', '', '', '', '', number_format($amount / 100, 2, '.', '')]);
                }
                fclose($stream);
            }, 'sales-summary-'.$start->toDateString().'-'.$end->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }

        return response()->view('reports.sales-summary', compact('business', 'kiosks', 'kiosk', 'periods', 'period', 'start', 'end', 'report'))
            ->header('Cache-Control', 'private, no-store');
    }

    public static function paymentLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'gcash' => 'GCash',
            'card' => 'Card',
            default => 'Other ('.str($method)->headline().')',
        };
    }
}
