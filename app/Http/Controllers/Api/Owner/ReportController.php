<?php
namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private ReportService $reportService) {}

    public function index(Request $request)
    {
        $request->validate(['start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);

        $tenant = $request->user()->isOwner() 
            ? $request->user()->tenant 
            : $request->user()->staffTenants()->first();

        if (!$tenant) {
            return response()->json(['status' => false, 'message' => 'Tenant tidak ditemukan.'], 403);
        }

        $tenantId = $tenant->id;
        $report   = $this->reportService->getSalesReport($tenantId, $request->start_date, $request->end_date);

        $orders = \App\Models\Order::with(['items'])
            ->where('tenant_id', $tenantId)
            ->where('status', \App\Models\Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [
                \Carbon\Carbon::parse($request->start_date)->startOfDay(),
                \Carbon\Carbon::parse($request->end_date)->endOfDay(),
            ])
            ->paginate(15);

        $summary = $report;
        unset($summary['orders']);

        // Rename avg_order to avg_order_value for frontend compatibility
        $summary['avg_order_value'] = $summary['avg_order'] ?? 0;

        return response()->json([
            'status'  => true,
            'message' => 'Berhasil',
            'data'    => $orders,
            'summary' => $summary,
        ], 200);
    }

    public function exportPdf(Request $request)
    {
        $request->validate(['start_date' => 'required|date', 'end_date' => 'required|date']);

        $tenantId = $request->user()->tenant->id;
        $pdf      = $this->reportService->exportPdf($tenantId, $request->start_date, $request->end_date);

        return $pdf->download("laporan-{$request->start_date}-{$request->end_date}.pdf");
    }

    public function exportCsv(Request $request)
    {
        $request->validate(['start_date' => 'required|date', 'end_date' => 'required|date']);

        $tenantId = $request->user()->tenant->id;
        $csv      = $this->reportService->exportCsv($tenantId, $request->start_date, $request->end_date);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=laporan-{$request->start_date}-{$request->end_date}.csv",
        ]);
    }

    /**
     * GET /owner/finance/summary
     * Returns financial summary for the merchant's tenant.
     */
    public function finance(Request $request)
    {
        $tenant = $request->user()->isOwner()
            ? $request->user()->tenant
            : $request->user()->staffTenants()->first();

        if (!$tenant) {
            return $this->error('Tenant tidak ditemukan.', 403);
        }

        $startDate = $request->input('start_date', \Carbon\Carbon::today()->subDays(29)->toDateString());
        $endDate   = $request->input('end_date', \Carbon\Carbon::today()->toDateString());

        $report = $this->reportService->getSalesReport($tenant->id, $startDate, $endDate);

        $totalRevenue  = (float) ($report['total_revenue'] ?? 0);
        $feeType       = \App\Models\SystemSetting::get('fee_type', 'percentage');
        $feeValue      = (float) \App\Models\SystemSetting::get('fee_value', 0);
        $platformFee   = $feeType === 'percentage'
            ? round($totalRevenue * ($feeValue / 100), 2)
            : $feeValue;
        $netIncome     = $totalRevenue - $platformFee;

        // Withdrawal history from subscriptions (placeholder – real withdrawal model TBD)
        $withdrawals = \App\Models\Subscription::where('tenant_id', $tenant->id)
            ->where('billing_status', 'paid')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'plan', 'amount', 'billing_status', 'created_at']);

        return $this->success([
            'total_revenue' => $totalRevenue,
            'platform_fee'  => $platformFee,
            'fee_type'      => $feeType,
            'fee_value'     => $feeValue,
            'net_income'    => $netIncome,
            'total_orders'  => (int) ($report['total_orders'] ?? 0),
            'withdrawals'   => $withdrawals,
        ]);
    }

    public function aggregate(Request $request)
    {
        $today     = \Carbon\Carbon::today()->toDateString();
        $startDate = $request->input('start_date', \Carbon\Carbon::today()->subDays(29)->toDateString());
        $endDate   = $request->input('end_date',   $today);

        $request->merge(['start_date' => $startDate, 'end_date' => $endDate]);
        $request->validate(['start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date']);

        // If called via owner route, scope data to their tenant
        $user = $request->user();
        if (!$user->isAdmin()) {
            $tenant = $user->isOwner()
                ? $user->tenant
                : $user->staffTenants()->first();

            if (!$tenant) {
                return response()->json(['status' => false, 'message' => 'Tenant tidak ditemukan.'], 403);
            }

            $data = $this->reportService->getSalesReport($tenant->id, $startDate, $endDate);

            // Calculate previous period
            $startCarbon = \Carbon\Carbon::parse($startDate);
            $endCarbon = \Carbon\Carbon::parse($endDate);
            $diffInDays = $startCarbon->diffInDays($endCarbon) + 1;

            $prevEndDate = $startCarbon->copy()->subDay()->toDateString();
            $prevStartDate = $startCarbon->copy()->subDays($diffInDays)->toDateString();
            
            $prevData = $this->reportService->getSalesReport($tenant->id, $prevStartDate, $prevEndDate);
            
            $calcTrend = function ($current, $prev) {
                if ($prev == 0) {
                    return $current > 0 ? 100 : 0;
                }
                return round((($current - $prev) / $prev) * 100, 1);
            };

            $revenueTrend = $calcTrend($data['total_revenue'] ?? 0, $prevData['total_revenue'] ?? 0);
            $ordersTrend = $calcTrend($data['total_orders'] ?? 0, $prevData['total_orders'] ?? 0);

            // Shape the response to match OverviewPage expectations
            $topMenus = collect($data['top_menus'] ?? [])->map(fn($m) => [
                'name'  => $m->menu_name ?? $m['name'] ?? '',
                'count' => $m->total_qty ?? $m['count'] ?? 0,
            ]);

            // Weekly trend (last 7 days, up to today)
            $last7Days = [];
            $indonesianDays = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
            for ($i = 6; $i >= 0; $i--) {
                $dateObj = \Carbon\Carbon::today()->subDays($i);
                $dateStr = $dateObj->toDateString();
                $last7Days[$dateStr] = [
                    'date'  => $dateStr,
                    'day'   => $indonesianDays[$dateObj->dayOfWeek],
                    'total' => 0,
                ];
            }
            
            foreach ($data['daily_chart'] ?? [] as $d) {
                $dDate = $d['date'] ?? $d->date ?? null;
                if ($dDate && isset($last7Days[$dDate])) {
                    $last7Days[$dDate]['total'] = $d['revenue'] ?? $d->revenue ?? 0;
                }
            }
            $dailyRevenue = array_values($last7Days);

            return $this->success([
                'summary' => [
                    'total_revenue'   => $data['total_revenue']  ?? 0,
                    'total_orders'    => $data['total_orders']   ?? 0,
                    'avg_order_value' => $data['avg_order']      ?? 0,
                    'avg_rating'      => 0,
                    'revenue_trend'   => $revenueTrend,
                    'orders_trend'    => $ordersTrend,
                ],
                'top_menus'      => $topMenus,
                'daily_revenue'  => $dailyRevenue,
            ]);
        }

        // Admin: platform-wide aggregate
        $data = $this->reportService->getAggregate($startDate, $endDate);

        return $this->success($data);
    }
}
