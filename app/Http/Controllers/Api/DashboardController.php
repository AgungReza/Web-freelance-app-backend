<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/today
     * Agenda booking hari ini milik user yang login
     */
    public function today(Request $request)
    {
        $today = Carbon::today();

        $bookings = Booking::with(['customer', 'payments'])
            ->where('user_id', $request->user()->id)
            ->whereDate('day_book', $today)
            ->orderBy('start_datetime')
            ->get()
            ->map(function ($b) {
                $totalPaid = $b->payments->sum('amount');

                return [
                    'id'             => $b->id,
                    'client_name'    => $b->customer?->name ?? '-',
                    'package_name'   => $b->job_package ?? '-',
                    'service_type'   => $b->job_type ?? '-',
                    'location'       => $b->location ?? '-',
                    'status'         => $b->work_status ?? 'Pending',
                    'payment_status' => $totalPaid > 0 ? 'Paid' : 'Unpaid',
                    'start_time'     => $b->start_datetime?->format('H:i'),
                    'end_time'       => $b->end_datetime?->format('H:i'),
                    'booking_date'   => $b->day_book?->toDateString(),
                    'total_paid'     => $totalPaid,
                ];
            });

        return response()->json([
            'data' => $bookings,
        ]);
    }

    /**
     * GET /api/dashboard/summary
     * Summary kartu bulan ini
     */
    public function summary(Request $request)
    {
        $userId = $request->user()->id;
        $now    = Carbon::now();

        $bookings = Booking::with(['customer', 'payments'])
            ->where('user_id', $userId)
            ->whereYear('day_book', $now->year)
            ->whereMonth('day_book', $now->month)
            ->get();

        // Cash in = semua pembayaran masuk bulan ini
        $cashIn = $bookings->flatMap->payments
            ->filter(fn($p) => Carbon::parse($p->payment_date)->isSameMonth($now))
            ->sum('amount');

        // Recognized revenue = total_paid dari booking yang Done
        $recognizedRevenue = $bookings
            ->where('work_status', 'Done')
            ->sum(fn($b) => $b->payments->sum('amount'));

        // Client unik
        $uniqueClients = $bookings
            ->pluck('customer.name')
            ->filter()
            ->unique()
            ->count();

        return response()->json([
            'data' => [
                'income'   => $recognizedRevenue,
                'cash_in'  => $cashIn,
                'clients'  => $uniqueClients,
                'bookings' => $bookings->count(),
            ],
        ]);
    }
}