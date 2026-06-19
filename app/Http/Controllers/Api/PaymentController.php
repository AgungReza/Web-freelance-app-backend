<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentExceededException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\{Booking, BookingFinance, BookingPayment};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log, Validator};

class PaymentController extends Controller
{
    // ----------------------------------------------------------
    // POST /api/bookings/{bookingId}/payments
    // ----------------------------------------------------------

    public function store(Request $request, int $bookingId): JsonResponse
    {
        $booking = Booking::with('finance')
            ->where('user_id', $request->user()->id)
            ->find($bookingId);

        if (!$booking) {
            return ApiResponse::error('Booking tidak ditemukan', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'required|in:Cash,Transfer,QRIS',
            'payment_date'   => 'nullable|date',
            'note'           => 'nullable|string',
            'proof'          => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            $finance = BookingFinance::where('booking_id', $bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            $totalPaid = BookingPayment::where('booking_id', $bookingId)
                ->lockForUpdate()
                ->sum('amount');

            $newTotal = $totalPaid + $request->amount;

            if ($newTotal > $finance->final_price) {
                throw new PaymentExceededException();
            }

            $proofPath = null;
            if ($request->hasFile('proof')) {
                $proofPath = $request->file('proof')->store('proofs', 'public');
            }

            $paymentType = match (true) {
                $newTotal >= $finance->final_price => 'Pelunasan',
                $totalPaid == 0                    => 'DP',
                default                            => 'Partial',
            };

            $payment = BookingPayment::create([
                'booking_id'     => $bookingId,
                'payment_type'   => $paymentType,
                'amount'         => $request->amount,
                'payment_method' => $request->payment_method,
                'payment_date'   => $request->input('payment_date', now()),
                'proof'          => $proofPath,
                'note'           => $request->note,
            ]);

            DB::commit();

            return ApiResponse::success('Pembayaran berhasil ditambahkan', $payment, 201);

        } catch (PaymentExceededException $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), null, 422);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[PaymentController@store] Gagal menambahkan pembayaran', [
                'booking_id'    => $bookingId,
                'error_message' => $e->getMessage(),
                'trace'         => app()->isProduction() ? '[hidden]' : $e->getTraceAsString(),
            ]);

            return ApiResponse::error('Internal server error', null, 500);
        }
    }
}