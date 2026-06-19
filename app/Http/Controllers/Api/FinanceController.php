<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\BookingPayment;
use App\Models\GeneralExpense;
use App\Models\ClientExpense;
use Illuminate\Http\{JsonResponse, Request};
use App\Models\Booking;

class FinanceController extends Controller
{
    // ----------------------------------------------------------
    // GET /api/finance/monthly-summary
    // Query param: ?month=5&year=2026 (default: bulan & tahun ini)
    // ----------------------------------------------------------

    public function monthlySummary(Request $request): JsonResponse
    {
        $month  = (int) $request->query('month', now()->month);
        $year   = (int) $request->query('year',  now()->year);
        $userId = $request->user()->id;

        // ── 1. CASH IN ────────────────────────────────────────────
        // Semua pembayaran masuk bulan ini milik user ini
        // BookingPayment tidak punya user_id langsung,
        // jadi join lewat booking → user_id
        $cashIn = BookingPayment::whereHas('booking', fn($q) =>
                $q->where('user_id', $userId)
            )
            ->whereMonth('payment_date', $month)
            ->whereYear('payment_date',  $year)
            ->sum('amount');

        // ── 2. RECOGNIZED REVENUE ────────────────────────────────
        // Pembayaran dari booking yang work_status = 'Done'
        $recognizedRevenue = BookingPayment::whereHas('booking', fn($q) =>
                $q->where('user_id',     $userId)
                  ->where('work_status', 'Done')
            )
            ->whereMonth('payment_date', $month)
            ->whereYear('payment_date',  $year)
            ->sum('amount');

        // ── 3. UNRECOGNIZED (DP / belum diakui) ──────────────────
        // Cash In dari booking yang belum Done
        $unrecognized = max(0, $cashIn - $recognizedRevenue);

        // ── 4. EXPENSE ────────────────────────────────────────────
        // GeneralExpense: pengeluaran umum (kolom: expense_date, amount)
        $generalExpense = GeneralExpense::where('user_id', $userId)
            ->whereMonth('expense_date', $month)
            ->whereYear('expense_date',  $year)
            ->sum('amount');

        // ClientExpense: pengeluaran per klien (kolom: expense_date, amount)
        $clientExpense = ClientExpense::where('user_id', $userId)
            ->whereMonth('expense_date', $month)
            ->whereYear('expense_date',  $year)
            ->sum('amount');

        $totalExpense = $generalExpense + $clientExpense;

        // ── 5. PROFIT & MARGIN ────────────────────────────────────
        // Profit  = Recognized Revenue - Total Expense
        // Margin  = Profit / Recognized Revenue * 100
        $profit = $recognizedRevenue - $totalExpense; // bisa negatif (rugi)
        $profitMargin = $recognizedRevenue > 0
            ? round(($profit / $recognizedRevenue) * 100, 1)
            : 0;

        return ApiResponse::success('Monthly summary berhasil diambil', [
            'month'              => $month,
            'year'               => $year,

            'cash_in'            => (float) $cashIn,
            'recognized_revenue' => (float) $recognizedRevenue,
            'unrecognized'       => (float) $unrecognized,

            'general_expense'    => (float) $generalExpense,
            'client_expense'     => (float) $clientExpense,
            'total_expense'      => (float) $totalExpense,

            'profit'             => (float) $profit,
            'profit_margin'      => $profitMargin,  // dalam persen (%)
        ]);
    }
    // ----------------------------------------------------------
    // GET /api/finance/kpi
    // All-time summary untuk kartu KPI utama
    // ----------------------------------------------------------
    public function kpi(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
 
        // Cash In = semua pembayaran masuk milik user
        $cashIn = BookingPayment::whereHas('booking', fn($q) =>
                $q->where('user_id', $userId)
            )->sum('amount');
 
        // Recognized Revenue = pembayaran dari booking Done
        $recognizedRevenue = BookingPayment::whereHas('booking', fn($q) =>
                $q->where('user_id',     $userId)
                  ->where('work_status', 'Done')
            )->sum('amount');
 
        // Total Expense = general + client
        $generalExpense = GeneralExpense::where('user_id', $userId)->sum('amount');
        $clientExpense  = ClientExpense::where('user_id', $userId)->sum('amount');
        $totalExpense   = $generalExpense + $clientExpense;
 
        // Net Profit & Margin
        $netProfit    = $recognizedRevenue - $totalExpense;
        $profitMargin = $recognizedRevenue > 0
            ? round(($netProfit / $recognizedRevenue) * 100, 1)
            : 0;
 
        return ApiResponse::success('KPI berhasil diambil', [
            'cash_in'            => (float) $cashIn,
            'recognized_revenue' => (float) $recognizedRevenue,
            'total_expense'      => (float) $totalExpense,
            'net_profit'         => (float) $netProfit,
            'profit_margin'      => $profitMargin,
        ]);
    }
 
    // ----------------------------------------------------------
    // GET /api/finance/charts?year=2026
    // Data income & expense per bulan untuk grafik tahunan
    // ----------------------------------------------------------
    public function charts(Request $request): JsonResponse
    {
        $year   = (int) $request->query('year', now()->year);
        $userId = $request->user()->id;
 
        // Income per bulan (dari BookingPayment)
        $incomeRaw = BookingPayment::whereHas('booking', fn($q) =>
                $q->where('user_id', $userId)
            )
            ->whereYear('payment_date', $year)
            ->selectRaw('MONTHNAME(payment_date) as month, SUM(amount) as amount')
            ->groupByRaw('MONTH(payment_date), MONTHNAME(payment_date)')
            ->orderByRaw('MONTH(payment_date)')
            ->get();
 
        // Expense per bulan (general + client digabung)
        $generalRaw = GeneralExpense::where('user_id', $userId)
            ->whereYear('expense_date', $year)
            ->selectRaw('MONTHNAME(expense_date) as month, SUM(amount) as amount')
            ->groupByRaw('MONTH(expense_date), MONTHNAME(expense_date)')
            ->orderByRaw('MONTH(expense_date)')
            ->get();
 
        $clientRaw = ClientExpense::where('user_id', $userId)
            ->whereYear('expense_date', $year)
            ->selectRaw('MONTHNAME(expense_date) as month, SUM(amount) as amount')
            ->groupByRaw('MONTH(expense_date), MONTHNAME(expense_date)')
            ->orderByRaw('MONTH(expense_date)')
            ->get();
 
        // Gabungkan general + client expense per bulan
        $expenseMap = [];
        foreach ($generalRaw as $item) {
            $expenseMap[$item->month] = ($expenseMap[$item->month] ?? 0) + (float) $item->amount;
        }
        foreach ($clientRaw as $item) {
            $expenseMap[$item->month] = ($expenseMap[$item->month] ?? 0) + (float) $item->amount;
        }
 
        $expenseChart = collect($expenseMap)->map(fn($amount, $month) => [
            'month'  => $month,
            'amount' => $amount,
        ])->values();
 
        return ApiResponse::success('Charts berhasil diambil', [
            'year'          => $year,
            'income_chart'  => $incomeRaw->map(fn($i) => [
                'month'  => $i->month,
                'amount' => (float) $i->amount,
            ]),
            'expense_chart' => $expenseChart,
        ]);
    }
 
    // ----------------------------------------------------------
    // GET /api/finance/activity?filter=month|3month|year|all
    // Riwayat transaksi (income + expense) untuk tabel aktivitas
    // ----------------------------------------------------------
    public function activity(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $filter = $request->query('filter', 'month');
 
        $from = match ($filter) {
            '3month' => now()->subMonths(3)->startOfDay(),
            'year'   => now()->subYear()->startOfDay(),
            'all'    => null,
            default  => now()->startOfMonth(), // 'month'
        };
 
        // ── Income (BookingPayment) ───────────────────────────────
        $paymentsQuery = BookingPayment::with('booking')
            ->whereHas('booking', fn($q) => $q->where('user_id', $userId));
 
        if ($from) {
            $paymentsQuery->where('payment_date', '>=', $from);
        }
 
        $payments = $paymentsQuery->orderByDesc('payment_date')->get()
            ->map(fn($p) => [
                'id'          => 'pay-' . $p->id,
                'date'        => $p->payment_date?->format('d M Y'),
                'type'        => 'Pemasukan',
                'description' => 'Booking ' . ($p->booking->booking_code ?? '#' . $p->booking_id),
                'amount'      => (float) $p->amount,
                'category'    => 'income',
            ]);
 
        // ── General Expense ───────────────────────────────────────
        $generalQuery = GeneralExpense::where('user_id', $userId);
        if ($from) {
            $generalQuery->where('expense_date', '>=', $from);
        }
 
        $generalExpenses = $generalQuery->orderByDesc('expense_date')->get()
            ->map(fn($e) => [
                'id'          => 'gen-' . $e->id,
                'date'        => $e->expense_date?->format('d M Y'),
                'type'        => 'Pengeluaran',
                'description' => $e->description ?? 'General Expense',
                'amount'      => (float) $e->amount,
                'category'    => 'expense',
            ]);
 
        // ── Client Expense ────────────────────────────────────────
        $clientQuery = ClientExpense::where('user_id', $userId);
        if ($from) {
            $clientQuery->where('expense_date', '>=', $from);
        }
 
        $clientExpenses = $clientQuery->orderByDesc('expense_date')->get()
            ->map(fn($e) => [
                'id'          => 'cli-' . $e->id,
                'date'        => $e->expense_date?->format('d M Y'),
                'type'        => 'Pengeluaran',
                'description' => $e->description ?? 'Client Expense',
                'amount'      => (float) $e->amount,
                'category'    => 'expense',
            ]);
 
        // ── Gabungkan & urutkan by date desc ─────────────────────
        $activities = $payments
            ->concat($generalExpenses)
            ->concat($clientExpenses)
            ->sortByDesc('date')
            ->values();
 
        return ApiResponse::success('Activity berhasil diambil', $activities);
    }
}