<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Reporting\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    /**
     * Pixelforge's usage endpoint accepts at most 92 days per request.
     */
    private const int MAX_DAYS = 92;

    public function __invoke(Request $request, ProfitReport $report, PixelforgeClient $pixelforge): Response
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
        ]);

        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], 'UTC') : $today;
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], 'UTC') : $to->subDays(13);

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        $usageError = null;

        try {
            $usage = Cache::remember(
                "pixelforge:usage:{$from->toDateString()}:{$to->toDateString()}",
                now()->addMinute(),
                fn () => $pixelforge->usage($from, $to),
            );
        } catch (PixelforgeException $e) {
            report($e);
            $usage = null;
            $usageError = 'Could not load usage from Pixelforge, so provider costs cannot be cross-checked right now.';
        }

        return Inertia::render('admin/Report', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'report' => $report->build($from, $to, $usage),
            'usageError' => $usageError,
        ]);
    }
}
