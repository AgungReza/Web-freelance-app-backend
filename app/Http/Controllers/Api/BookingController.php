<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentExceededException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log, Validator};

/**
 * ============================================================
 * BookingController  (v1.3.0)
 * ============================================================
 *
 * Changelog dari v1.2.0:
 *   - work_status dikembalikan ke validasi store
 *     (bisa ditentukan dari frontend saat create)
 *
 * @see     App\Services\BookingService
 * @version 1.3.0
 * ============================================================
 */
class BookingController extends Controller
{
    public function __construct(protected BookingService $bookingService)
    {
    }

    // ----------------------------------------------------------
    // GET /api/bookings
    // ----------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $bookings = Booking::with(['customer', 'finance', 'addOns', 'payments', 'histories'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return ApiResponse::success('Booking berhasil diambil', $bookings);
    }

    // ----------------------------------------------------------
    // POST /api/bookings
    // ----------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        // -------------------------------------------------------
        // STEP 1 : Validasi Input
        // -------------------------------------------------------
        $validator = Validator::make($request->all(), [
            // -- Customer --
            'customer_name' => 'required|string|max:255',
            'phone'         => 'required|string|max:50',
            'email'         => 'nullable|email',
            'instagram'     => 'nullable|string|max:255',

            // -- Booking --
            'job_package_id'  => 'required|exists:job_packages,id',
            'day_book'        => 'required|date',
            'start_datetime'  => 'required|date',
            'end_datetime'    => 'required|date|after:start_datetime',
            'location'        => 'required|string|max:255',

            // FIX (v1.3.0): work_status dikembalikan — bisa ditentukan dari frontend
            'work_status' => 'nullable|in:Pending,Reserved,In Progress,Done,Cancelled',

            // -- Finance --
            'discount' => 'nullable|numeric|min:0',

            // -- Add-on (opsional) --
            'addons'               => 'nullable|array',
            'addons.*.add_on_name' => 'required_with:addons|string|max:255',
            'addons.*.qty'         => 'required_with:addons|integer|min:1',
            'addons.*.price'       => 'required_with:addons|numeric|min:0',
            'addons.*.description' => 'nullable|string',

            // -- Pembayaran awal (opsional) --
            'payment'                => 'nullable|array',
            'payment.amount'         => 'nullable|numeric|min:1',
            'payment.payment_method' => 'required_with:payment.amount|in:Cash,Transfer,QRIS',
            'payment.note'           => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            // STEP 2 : Resolve Customer
            $customer = $this->bookingService->resolveCustomer(
                userId: $request->user()->id,
                phone:  $request->phone,
                data:   [
                    'name'      => $request->customer_name,
                    'email'     => $request->email,
                    'instagram' => $request->instagram,
                ]
            );

            // STEP 3 : Validasi & Ambil Job Package
            $jobPackage = $this->bookingService->findPackageForUser(
                packageId: $request->job_package_id,
                userId:    $request->user()->id
            );

            if (!$jobPackage) {
                DB::rollBack();
                return ApiResponse::error('Paket tidak ditemukan', null, 404);
            }

            // STEP 4 : Buat Record Booking
            $booking = $this->bookingService->createBooking(
                userId:     $request->user()->id,
                customerId: $customer->id,
                jobPackage: $jobPackage,
                data:       $request->only([
                    'day_book', 'start_datetime', 'end_datetime', 'location', 'work_status',
                ])
            );

            // STEP 5 : Simpan Add-on
            $addonTotal = $this->bookingService->attachAddons(
                bookingId: $booking->id,
                addons:    $request->input('addons', [])
            );

            // STEP 6 : Simpan Data Keuangan
            $finance = $this->bookingService->createFinance(
                bookingId:    $booking->id,
                packagePrice: (float) $jobPackage->price,
                addonTotal:   $addonTotal,
                discount:     (float) $request->input('discount', 0)
            );

            // STEP 7 : Proses Pembayaran Awal
            $this->bookingService->processInitialPayment(
                bookingId: $booking->id,
                finance:   $finance,
                payment:   $request->input('payment', [])
            );

            // STEP 8 : Catat History
            $this->bookingService->recordHistory(
                bookingId:   $booking->id,
                userId:      $request->user()->id,
                activity:    'created',
                description: sprintf(
                    'Booking %s dibuat oleh %s — Total Rp %s',
                    $booking->booking_code,
                    $request->user()->name,
                    number_format($finance->final_price, 0, ',', '.')
                )
            );

            DB::commit();

            $booking->load(['customer', 'finance', 'addOns', 'payments', 'histories']);

            return ApiResponse::success('Booking berhasil dibuat', $booking, 201);

        } catch (PaymentExceededException $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), null, 422);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[BookingController@store] Gagal membuat booking', [
                'user_id'        => $request->user()->id,
                'job_package_id' => $request->job_package_id,
                'error_message'  => $e->getMessage(),
                'error_file'     => $e->getFile(),
                'error_line'     => $e->getLine(),
                'trace'          => app()->isProduction() ? '[hidden]' : $e->getTraceAsString(),
            ]);

            return ApiResponse::error('Internal server error', null, 500);
        }
    }
    // ----------------------------------------------------------
    // GET /api/bookings/{id}
    // ----------------------------------------------------------

    public function show(Request $request, int $id): JsonResponse
    {
        $booking = Booking::with(['customer', 'finance', 'addOns', 'payments', 'histories'])
            ->where('user_id', $request->user()->id) // pastikan milik user ini
            ->find($id);

        if (!$booking) {
            return ApiResponse::error('Booking tidak ditemukan', null, 404);
        }

        return ApiResponse::success('Booking berhasil diambil', $booking);
    }
    public function search(Request $request)
    {
        $q = $request->query('q', '');

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $bookings = Booking::where('user_id', $request->user()->id)
            ->where(function ($query) use ($q) {
                $query->where('booking_code', 'LIKE', "%{$q}%")
                    ->orWhere('job_type', 'LIKE', "%{$q}%")
                    ->orWhereHas('customer', function ($q2) use ($q) {
                        $q2->where('name', 'LIKE', "%{$q}%");
                    });
            })
            ->with('customer:id,name')
            ->select('id', 'booking_code', 'customer_id', 'job_type', 'job_package', 'day_book')
            ->orderByDesc('day_book')
            ->limit(10)
            ->get()
            ->map(function ($b) {
                return [
                    'id'           => $b->id,
                    'booking_code' => $b->booking_code,
                    'client_name'  => $b->customer->name ?? '-',
                    'booking_date' => $b->day_book?->format('Y-m-d'),
                    'service_type' => $b->job_type,
                ];
            });

        return response()->json($bookings);
    }
    // ----------------------------------------------------------
    // PUT /api/bookings/{id}
    // ----------------------------------------------------------

    public function update(Request $request, int $id): JsonResponse
    {
        $booking = Booking::with(['customer', 'finance', 'addOns', 'payments', 'histories'])
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (!$booking) {
            return ApiResponse::error('Booking tidak ditemukan', null, 404);
        }

        $validator = Validator::make($request->all(), [
            // -- Customer --
            'customer_name' => 'sometimes|string|max:255',
            'phone'         => 'sometimes|string|max:50',
            'email'         => 'nullable|email',
            'instagram'     => 'nullable|string|max:255',

            // -- Booking --
            'day_book'       => 'sometimes|date',
            'start_datetime' => 'sometimes|date',
            'end_datetime'   => 'sometimes|date|after:start_datetime',
            'location'       => 'sometimes|string|max:255',
            'work_status'    => 'sometimes|in:Pending,Reserved,In Progress,Done,Cancelled',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            // Update customer
            if ($booking->customer) {
                $fillData = [
                    'name'      => $request->input('customer_name', $booking->customer->name),
                    'email'     => $request->input('email', $booking->customer->email),
                    'instagram' => $request->input('instagram', $booking->customer->instagram),
                ];

                // Update phone jika dikirim, dengan normalisasi format
                if ($request->filled('phone')) {
                    $phone = preg_replace('/\D/', '', $request->input('phone'));
                    if (str_starts_with($phone, '62'))     $phone = $phone;
                    elseif (str_starts_with($phone, '08')) $phone = '62' . substr($phone, 1);
                    elseif (str_starts_with($phone, '8'))  $phone = '62' . $phone;

                    $fillData['phone'] = $phone;
                }

                $booking->customer->fill($fillData);
                if ($booking->customer->isDirty()) {
                    $booking->customer->save();
                }
            }

            // Update booking fields
            $booking->fill($request->only([
                'day_book', 'start_datetime', 'end_datetime', 'location', 'work_status',
            ]));
            if ($booking->isDirty()) {
                $booking->save();
            }

            // Catat history jika ada perubahan
            $this->bookingService->recordHistory(
                bookingId:   $booking->id,
                userId:      $request->user()->id,
                activity:    'updated',
                description: sprintf(
                    'Booking %s diupdate oleh %s',
                    $booking->booking_code,
                    $request->user()->name
                )
            );

            DB::commit();

            $booking->load(['customer', 'finance', 'addOns', 'payments', 'histories']);

            return ApiResponse::success('Booking berhasil diupdate', $booking);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[BookingController@update] Gagal update booking', [
                'booking_id'    => $id,
                'user_id'       => $request->user()->id,
                'error_message' => $e->getMessage(),
                'trace'         => app()->isProduction() ? '[hidden]' : $e->getTraceAsString(),
            ]);

            return ApiResponse::error('Internal server error', null, 500);
        }
    }
}