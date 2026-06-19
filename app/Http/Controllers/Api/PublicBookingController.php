<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentExceededException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\JobPackage;
use App\Models\JobType;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Log, Validator};

/**
 * ============================================================
 * PublicBookingController  (v2.1.0)
 * ============================================================
 *
 * Menangani seluruh request dari form publik tanpa autentikasi.
 * Semua data (job-types, packages, booking) diisolasi per
 * user_code — client dari user_code lain tidak bisa mengakses.
 *
 * Routes yang ditangani:
 *   GET  /public/{user_code}                                      → hello()
 *   GET  /public/{user_code}/job-types                            → jobTypes()
 *   GET  /public/{user_code}/packages/{jobTypeId}                 → packages()
 *   POST /public/booking/{user_code}                              → store()
 *   GET  /public/{user_code}/booking/search?booking_code=XXX      → search()
 *   GET  /public/{user_code}/booking/{booking_code}               → show()
 *   POST /public/{user_code}/booking/{booking_code}/payment       → addPayment()
 *
 * ============================================================
 */
class PublicBookingController extends Controller
{
    public function __construct(protected BookingService $bookingService) {}

    // ----------------------------------------------------------
    // Helper: resolve user dari user_code, return null jika tidak ada
    // ----------------------------------------------------------
    private function resolveUser(string $userCode): ?User
    {
        return User::where('user_code', $userCode)->first();
    }

    // ----------------------------------------------------------
    // Helper: build payload detail booking (dipakai di show() & addPayment())
    // Selalu fresh dari DB agar customer melihat data terkini
    // ----------------------------------------------------------
    private function buildBookingDetail(Booking $booking): array
    {
        // Reload semua relasi agar data selalu up-to-date
        $booking->load(['addOns', 'payments', 'finance', 'customer']);

        $finance  = $booking->finance;
        $customer = $booking->customer;

        $totalPaid = $booking->payments->sum('amount');
        $remaining = $finance ? max(0, $finance->final_price - $totalPaid) : 0;

        return [
            'booking_code'   => $booking->booking_code,
            'work_status'    => $booking->work_status,
            'day_book'       => $booking->day_book,
            'start_datetime' => $booking->start_datetime,
            'end_datetime'   => $booking->end_datetime,
            'location'       => $booking->location,
            'note'           => $booking->note ?? '',
            'job_type'       => $booking->job_type,
            'job_package'    => $booking->job_package,

            'customer' => $customer ? [
                'customer_code' => $customer->customer_code,
                'name'          => $customer->name,
                'phone'         => $customer->phone,
                'email'         => $customer->email,
                'instagram'     => $customer->instagram,
            ] : null,

            'finance' => $finance ? [
                'price'       => $finance->price,
                'discount'    => $finance->discount,
                'final_price' => $finance->final_price,
                'total_paid'  => $totalPaid,
                'remaining'   => $remaining,
                'is_paid_off' => $remaining <= 0,
            ] : null,

            'add_ons' => $booking->addOns->map(fn($a) => [
                'id'          => $a->id,
                'add_on_name' => $a->add_on_name,
                'qty'         => $a->qty,
                'price'       => $a->price,
                'subtotal'    => $a->qty * $a->price,
                'description' => $a->description,
            ])->values(),

            'payments' => $booking->payments
                ->sortByDesc('created_at')
                ->map(fn($p) => [
                    'id'             => $p->id,
                    'amount'         => $p->amount,
                    'payment_method' => $p->payment_method,
                    'payment_date'   => $p->payment_date ?? $p->created_at,
                    'note'           => $p->note,
                ])->values(),
        ];
    }

    // ----------------------------------------------------------
    // GET /api/public/{user_code}
    // Kembalikan info pemilik form — ditampilkan di halaman publik
    // ----------------------------------------------------------
    public function hello(string $userCode): JsonResponse
    {
        $user = $this->resolveUser($userCode);

        if (! $user) {
            return ApiResponse::error('Link booking tidak valid', null, 404);
        }

        return ApiResponse::success('OK', [
            'owner' => [
                'name'  => $user->fullname ?? $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    // ----------------------------------------------------------
    // GET /api/public/{user_code}/job-types
    // Kembalikan daftar jenis layanan milik user tersebut saja
    // ----------------------------------------------------------
    public function jobTypes(string $userCode): JsonResponse
    {
        $user = $this->resolveUser($userCode);

        if (! $user) {
            return ApiResponse::error('Link booking tidak valid', null, 404);
        }

        $jobTypes = JobType::where('user_id', $user->id)
            ->orderBy('job_name')
            ->get(['id', 'job_name']);

        return ApiResponse::success('OK', [
            'owner' => [
                'name' => $user->fullname ?? $user->name,
            ],
            'data' => $jobTypes,
        ]);
    }

    // ----------------------------------------------------------
    // GET /api/public/{user_code}/packages/{jobTypeId}
    // Kembalikan paket dari job type tertentu — divalidasi milik user
    // ----------------------------------------------------------
    public function packages(string $userCode, int $jobTypeId): JsonResponse
    {
        $user = $this->resolveUser($userCode);

        if (! $user) {
            return ApiResponse::error('Link booking tidak valid', null, 404);
        }

        // Pastikan job type ini benar-benar milik user tsb
        $jobType = JobType::where('id', $jobTypeId)
            ->where('user_id', $user->id)
            ->first();

        if (! $jobType) {
            return ApiResponse::error('Jenis layanan tidak ditemukan', null, 404);
        }

        $packages = JobPackage::where('job_type_id', $jobTypeId)
            ->orderBy('package_name')
            ->get(['id', 'package_name', 'price', 'discount', 'description']);

        return ApiResponse::success('OK', [
            'data' => $packages,
        ]);
    }

    // ----------------------------------------------------------
    // POST /api/public/booking/{user_code}
    // Customer submit booking baru tanpa login
    // ----------------------------------------------------------
    public function store(Request $request, string $userCode): JsonResponse
    {
        // -------------------------------------------------------
        // STEP 1 : Identifikasi pemilik form via user_code
        // -------------------------------------------------------
        $user = $this->resolveUser($userCode);

        if (! $user) {
            return ApiResponse::error('Link booking tidak valid', null, 404);
        }

        // -------------------------------------------------------
        // STEP 2 : Validasi Input
        // -------------------------------------------------------
        $validator = Validator::make($request->all(), [
            // -- Customer --
            'customer_name' => 'required|string|max:255',
            'phone'         => 'required|string|max:50',
            'email'         => 'nullable|email|max:255',
            'instagram'     => 'nullable|string|max:255',

            // -- Booking --
            'job_package_id' => 'required|integer|exists:job_packages,id',
            'day_book'       => 'required|date',
            'start_datetime' => 'required|date',
            'end_datetime'   => 'required|date|after:start_datetime',
            'location'       => 'required|string|max:255',
            'note'           => 'nullable|string|max:1000',

            // -- Finance --
            'discount' => 'nullable|numeric|min:0',

            // -- Add-on (opsional) --
            'addons'               => 'nullable|array',
            'addons.*.add_on_name' => 'required_with:addons|string|max:255',
            'addons.*.qty'         => 'required_with:addons|integer|min:1',
            'addons.*.price'       => 'required_with:addons|numeric|min:0',
            'addons.*.description' => 'nullable|string|max:500',

            // -- Pembayaran awal (opsional) --
            'payment'                => 'nullable|array',
            'payment.amount'         => 'nullable|numeric|min:1',
            'payment.payment_method' => 'required_with:payment.amount|in:Cash,Transfer,QRIS',
            'payment.note'           => 'nullable|string|max:500',
            'payment.proof'          => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            // -------------------------------------------------------
            // STEP 3 : Resolve Customer
            // -------------------------------------------------------
            $customer = $this->bookingService->resolveCustomer(
                userId: $user->id,
                phone: $request->phone,
                data: [
                    'name'      => $request->customer_name,
                    'email'     => $request->email,
                    'instagram' => $request->instagram,
                ]
            );

            // -------------------------------------------------------
            // STEP 4 : Validasi Job Package — wajib milik user tsb
            // -------------------------------------------------------
            $jobPackage = $this->bookingService->findPackageForUser(
                packageId: (int) $request->job_package_id,
                userId: $user->id
            );

            if (! $jobPackage) {
                DB::rollBack();
                return ApiResponse::error('Paket tidak ditemukan atau bukan milik pemilik form ini', null, 404);
            }

            // -------------------------------------------------------
            // STEP 5 : Buat Record Booking
            // -------------------------------------------------------
            $booking = $this->bookingService->createBooking(
                userId: $user->id,
                customerId: $customer->id,
                jobPackage: $jobPackage,
                data: [
                    'day_book'       => $request->day_book,
                    'start_datetime' => $request->start_datetime,
                    'end_datetime'   => $request->end_datetime,
                    'location'       => $request->location,
                    'note'           => $request->input('note', ''),
                    'work_status'    => 'Pending',
                ]
            );

            // -------------------------------------------------------
            // STEP 6 : Simpan Add-on (opsional)
            // -------------------------------------------------------
            $addonTotal = $this->bookingService->attachAddons(
                bookingId: $booking->id,
                addons: $request->input('addons', [])
            );

            // -------------------------------------------------------
            // STEP 7 : Simpan Data Keuangan
            // -------------------------------------------------------
            $finance = $this->bookingService->createFinance(
                bookingId: $booking->id,
                packagePrice: (float) $jobPackage->price,
                addonTotal: $addonTotal,
                discount: (float) $request->input('discount', 0)
            );

            // -------------------------------------------------------
            // STEP 8 : Proses Pembayaran Awal (opsional)
            // -------------------------------------------------------
            $this->bookingService->processInitialPayment(
                bookingId: $booking->id,
                finance: $finance,
                payment: $request->input('payment', [])
            );

            // -------------------------------------------------------
            // STEP 9 : Catat History
            // -------------------------------------------------------
            $this->bookingService->recordHistory(
                bookingId: $booking->id,
                userId: $user->id,
                activity: 'created',
                description: sprintf(
                    'Booking %s dibuat oleh customer %s via form publik — Total Rp %s',
                    $booking->booking_code,
                    $customer->name,
                    number_format($finance->final_price, 0, ',', '.')
                )
            );

            DB::commit();

            return ApiResponse::success('Booking berhasil dikirim', $this->buildBookingDetail($booking), 201);
        } catch (PaymentExceededException $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), null, 422);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[PublicBookingController@store] Gagal membuat booking publik', [
                'user_code'      => $userCode,
                'job_package_id' => $request->job_package_id,
                'error_message'  => $e->getMessage(),
                'error_file'     => $e->getFile(),
                'error_line'     => $e->getLine(),
                'trace'          => app()->isProduction() ? '[hidden]' : $e->getTraceAsString(),
            ]);

            return ApiResponse::error('Terjadi kesalahan pada server', null, 500);
        }
    }

    // ----------------------------------------------------------
    // GET /api/public/{user_code}/booking/search?booking_code=XXX
    //
    // Customer mencari booking miliknya via booking_code.
    // Satu booking_code hanya milik satu user, tapi tetap
    // divalidasi ke user_code agar tidak lintas-akun.
    //
    // Response: array hasil (bisa lebih dari satu jika prefix
    // yang sama, tapi umumnya tepat satu record).
    // ----------------------------------------------------------
    public function search(Request $request, string $userCode): JsonResponse
    {
        $user = $this->resolveUser($userCode);

        if (! $user) {
            return ApiResponse::error('Link booking tidak valid', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'booking_code' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Kode booking wajib diisi', $validator->errors(), 422);
        }

        // Cari booking: cocokkan booking_code + user_id agar aman lintas-akun
        $bookings = Booking::where('user_id', $user->id)
            ->where('booking_code', 'LIKE', '%' . trim($request->booking_code) . '%')
            ->with(['customer', 'finance', 'payments'])
            ->orderByDesc('created_at')
            ->get();

        if ($bookings->isEmpty()) {
            return ApiResponse::error('Booking tidak ditemukan', null, 404);
        }

        // Ringkasan list — detail lengkap ada di endpoint show()
        $list = $bookings->map(function (Booking $b) {
            $finance   = $b->finance;
            $totalPaid = $b->payments->sum('amount');
            $remaining = $finance ? max(0, $finance->final_price - $totalPaid) : 0;

            return [
                'booking_code' => $b->booking_code,
                'work_status'  => $b->work_status,
                'day_book'     => $b->day_book,
                'job_type'     => $b->job_type,
                'job_package'  => $b->job_package,
                'location'     => $b->location,
                'customer'     => [
                    'name'  => $b->customer?->name,
                    'phone' => $b->customer?->phone,
                ],
                'finance' => $finance ? [
                    'final_price' => $finance->final_price,
                    'total_paid'  => $totalPaid,
                    'remaining'   => $remaining,
                    'is_paid_off' => $remaining <= 0,
                ] : null,
            ];
        });

        return ApiResponse::success('OK', ['data' => $list]);
    }

    // ----------------------------------------------------------
    // GET /api/public/{user_code}/booking/{booking_code}
    //
    // Detail lengkap satu booking — selalu fresh dari DB.
    // Customer hanya bisa READ; perubahan oleh admin akan
    // langsung terlihat saat endpoint ini di-hit ulang.
    // ----------------------------------------------------------
    public function show(string $userCode, string $bookingCode): JsonResponse
    {
        $user = $this->resolveUser($userCode);

        if (! $user) {
            return ApiResponse::error('Link booking tidak valid', null, 404);
        }

        $booking = Booking::where('user_id', $user->id)
            ->where('booking_code', $bookingCode)
            ->first();

        if (! $booking) {
            return ApiResponse::error('Booking tidak ditemukan', null, 404);
        }

        return ApiResponse::success('OK', $this->buildBookingDetail($booking));
    }

    // ----------------------------------------------------------
    // POST /api/public/{user_code}/booking/{booking_code}/payment
    //
    // Customer menambahkan pembayaran pelunasan.
    // Validasi:
    //   1. Booking harus milik user_code ini
    //   2. Booking belum lunas (remaining > 0)
    //   3. Amount tidak boleh melebihi sisa tagihan
    // ----------------------------------------------------------
    public function addPayment(Request $request, string $userCode, string $bookingCode): JsonResponse
    {
        $user = $this->resolveUser($userCode);

        if (! $user) {
            return ApiResponse::error('Link booking tidak valid', null, 404);
        }

        // -------------------------------------------------------
        // Resolve booking — wajib milik user ini
        // -------------------------------------------------------
        $booking = Booking::where('user_id', $user->id)
            ->where('booking_code', $bookingCode)
            ->with(['finance', 'payments'])
            ->first();

        if (! $booking) {
            return ApiResponse::error('Booking tidak ditemukan', null, 404);
        }

        // -------------------------------------------------------
        // Cek apakah sudah lunas
        // -------------------------------------------------------
        $finance   = $booking->finance;
        $totalPaid = $booking->payments->sum('amount');
        $remaining = $finance ? max(0, $finance->final_price - $totalPaid) : 0;

        if ($remaining <= 0) {
            return ApiResponse::error('Booking ini sudah lunas, tidak perlu pembayaran tambahan', null, 422);
        }

        // -------------------------------------------------------
        // Validasi input pembayaran
        // -------------------------------------------------------
        $validator = Validator::make($request->all(), [
            'amount'         => 'required|numeric|min:1|max:' . $remaining,
            'payment_method' => 'required|in:Cash,Transfer,QRIS',
            'note'           => 'nullable|string|max:500',
            'proof'          => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ], [
            'amount.max' => 'Jumlah pembayaran tidak boleh melebihi sisa tagihan (Rp ' . number_format($remaining, 0, ',', '.') . ')',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            // -------------------------------------------------------
            // Simpan pembayaran via BookingService::processInitialPayment
            // (method yang sama dipakai agar logic proof upload & finance
            //  update konsisten dengan alur store())
            // -------------------------------------------------------
            $this->bookingService->processInitialPayment(
                bookingId: $booking->id,
                finance: $finance,
                payment: [
                    'amount'         => $request->amount,
                    'payment_method' => $request->payment_method,
                    'note'           => $request->input('note', ''),
                    'proof'          => $request->file('proof'),
                ]
            );

            // -------------------------------------------------------
            // Catat history pembayaran oleh customer
            // -------------------------------------------------------
            $this->bookingService->recordHistory(
                bookingId: $booking->id,
                userId: $user->id,
                activity: 'payment',
                description: sprintf(
                    'Pembayaran Rp %s diterima dari customer via form publik (%s)',
                    number_format($request->amount, 0, ',', '.'),
                    $request->payment_method
                )
            );

            DB::commit();

            // Kembalikan detail terbaru — finance & payments sudah terupdate
            return ApiResponse::success(
                'Pembayaran berhasil ditambahkan',
                $this->buildBookingDetail($booking->fresh())
            );
        } catch (PaymentExceededException $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), null, 422);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[PublicBookingController@addPayment] Gagal menambahkan pembayaran publik', [
                'user_code'    => $userCode,
                'booking_code' => $bookingCode,
                'amount'       => $request->amount,
                'error_message' => $e->getMessage(),
                'error_file'    => $e->getFile(),
                'error_line'    => $e->getLine(),
                'trace'         => app()->isProduction() ? '[hidden]' : $e->getTraceAsString(),
            ]);

            return ApiResponse::error('Terjadi kesalahan pada server', null, 500);
        }
    }
}
