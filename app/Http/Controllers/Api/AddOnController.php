<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\{AddOn, Booking, BookingFinance};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log, Validator};

class AddOnController extends Controller
{
    // ----------------------------------------------------------
    // POST /api/bookings/{bookingId}/addons
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
            'add_on_name' => 'required|string|max:255',
            'qty'         => 'required|integer|min:1',
            'price'       => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            $subtotal = $request->qty * $request->price;

            $addon = AddOn::create([
                'booking_id'  => $bookingId,
                'add_on_name' => $request->add_on_name,
                'qty'         => $request->qty,
                'price'       => $request->price,
                'subtotal'    => $subtotal,
                'description' => $request->description,
            ]);

            // Recalculate finance
            $this->recalculateFinance($booking->finance, $bookingId);

            DB::commit();

            return ApiResponse::success('Add-on berhasil ditambahkan', $addon, 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[AddOnController@store] Gagal tambah addon', [
                'booking_id'    => $bookingId,
                'error_message' => $e->getMessage(),
                'trace'         => app()->isProduction() ? '[hidden]' : $e->getTraceAsString(),
            ]);

            return ApiResponse::error('Internal server error', null, 500);
        }
    }

    // ----------------------------------------------------------
    // DELETE /api/bookings/{bookingId}/addons/{addonId}
    // ----------------------------------------------------------

    public function destroy(Request $request, int $bookingId, int $addonId): JsonResponse
    {
        $booking = Booking::with('finance')
            ->where('user_id', $request->user()->id)
            ->find($bookingId);

        if (!$booking) {
            return ApiResponse::error('Booking tidak ditemukan', null, 404);
        }

        $addon = AddOn::where('booking_id', $bookingId)->find($addonId);

        if (!$addon) {
            return ApiResponse::error('Add-on tidak ditemukan', null, 404);
        }

        DB::beginTransaction();

        try {
            $addon->delete();

            // Recalculate finance
            $this->recalculateFinance($booking->finance, $bookingId);

            DB::commit();

            return ApiResponse::success('Add-on berhasil dihapus');

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[AddOnController@destroy] Gagal hapus addon', [
                'booking_id'    => $bookingId,
                'addon_id'      => $addonId,
                'error_message' => $e->getMessage(),
                'trace'         => app()->isProduction() ? '[hidden]' : $e->getTraceAsString(),
            ]);

            return ApiResponse::error('Internal server error', null, 500);
        }
    }

    // ----------------------------------------------------------
    // HELPER — Recalculate final_price
    // final_price = max(0, package_price + total_addons - discount)
    // ----------------------------------------------------------

    private function recalculateFinance(BookingFinance $finance, int $bookingId): void
    {
        $addonTotal = AddOn::where('booking_id', $bookingId)->sum('subtotal');

        $finance->update([
            'final_price' => max(0, $finance->price + $addonTotal - $finance->discount),
        ]);
    }
}