<?php

namespace App\Services;

use App\Exceptions\PaymentExceededException;
use App\Services\CodeGenerator;
use App\Models\{
    AddOn,
    Booking,
    BookingFinance,
    BookingHistory,
    BookingPayment,
    Customer,
    JobPackage
};

/**
 * ============================================================
 * BookingService  (v1.3.0)
 * ============================================================
 *
 * Changelog dari v1.2.0:
 *   - createBooking: work_status kembali dibaca dari $data
 *     (default 'Pending' jika tidak dikirim)
 *
 * @version 1.3.0
 * ============================================================
 */
class BookingService
{
    public function __construct(protected CodeGenerator $codeGenerator)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Customer
    |--------------------------------------------------------------------------
    */

    /**
     * Cari atau buat customer berdasarkan user_id + phone (ternormalisasi).
     *
     * Normalisasi nomor HP (semua format → 62xxx):
     *   +628123  → 628123
     *   08123    → 628123
     *   8123     → 628123
     *   628123   → 628123 (tidak berubah)
     *
     * Update profil HANYA jika ada perubahan (isDirty check).
     */
    public function resolveCustomer(
        int $userId,
        string $phone,
        array $data
    ): Customer {
        $phone = $this->normalizePhone($phone);

        $customer = Customer::firstOrCreate(
            [
                'user_id' => $userId,
                'phone'   => $phone,
            ],
            [
                'customer_code' => $this->codeGenerator->customerCode(),
                'name'          => $data['name'],
                'email'         => $data['email'] ?? null,
                'instagram'     => $data['instagram'] ?? null,
            ]
        );

        $customer->fill([
            'name'      => $data['name'],
            'email'     => $data['email'] ?? null,
            'instagram' => $data['instagram'] ?? null,
        ]);

        if ($customer->isDirty()) {
            $customer->save();
        }

        return $customer;
    }

    /**
     * Normalisasi nomor HP ke format 62xxx.
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        return match (true) {
            str_starts_with($phone, '62') => $phone,
            str_starts_with($phone, '08') => '62' . substr($phone, 1),
            str_starts_with($phone, '8')  => '62' . $phone,
            default                        => $phone,
        };
    }


    /*
    |--------------------------------------------------------------------------
    | Conflict Detection
    |--------------------------------------------------------------------------
    */

    /**
     * Cek konflik jadwal — hanya untuk warning di frontend, tidak memblokir.
     */
    public function checkConflict(int $userId, array $data): array
    {
        $conflicts = Booking::where('user_id', $userId)
            ->whereDate('day_book', $data['day_book'])
            ->where(function ($query) use ($data) {
                $query
                    ->where('start_datetime', '<', $data['end_datetime'])
                    ->where('end_datetime',   '>',  $data['start_datetime']);
            })
            ->get(['id', 'booking_code', 'customer_id', 'start_datetime', 'end_datetime', 'work_status']);

        return [
            'has_conflict' => $conflicts->isNotEmpty(),
            'total'        => $conflicts->count(),
            'data'         => $conflicts,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Booking
    |--------------------------------------------------------------------------
    */

    /**
     * Buat record booking baru.
     *
     * FIX (v1.3.0): work_status kembali dibaca dari $data.
     * Default 'Pending' jika frontend tidak mengirimkan nilai.
     *
     * Snapshot job_type & job_package agar data historis tidak berubah
     * jika package diedit di kemudian hari.
     */
    public function createBooking(
        int $userId,
        int $customerId,
        JobPackage $jobPackage,
        array $data
    ): Booking {
        return Booking::create([
            'booking_code' => $this->codeGenerator->bookingCode(),
            'user_id'      => $userId,
            'customer_id'  => $customerId,

            // Snapshot
            'job_type'    => $jobPackage->jobType->job_name,
            'job_package' => $jobPackage->package_name,

            'day_book'       => $data['day_book'],
            'start_datetime' => $data['start_datetime'],
            'end_datetime'   => $data['end_datetime'],
            'location'       => $data['location'],

            // FIX (v1.3.0): baca dari $data, default 'Pending'
            'work_status' => $data['work_status'] ?? 'Pending',
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Add On
    |--------------------------------------------------------------------------
    */

    /**
     * Simpan add-on dan kembalikan total harganya (dihitung di memori).
     */
    public function attachAddons(int $bookingId, array $addons): float
    {
        $total = 0.0;

        foreach ($addons as $addon) {
            $subtotal = $addon['qty'] * $addon['price'];
            $total   += $subtotal;

            AddOn::create([
                'booking_id'  => $bookingId,
                'add_on_name' => $addon['add_on_name'],
                'qty'         => $addon['qty'],
                'price'       => $addon['price'],
                'subtotal'    => $subtotal,
                'description' => $addon['description'] ?? null,
            ]);
        }

        return $total;
    }


    /*
    |--------------------------------------------------------------------------
    | Finance
    |--------------------------------------------------------------------------
    */

    /**
     * Hitung dan simpan data keuangan booking.
     * final_price = max(0, packagePrice + addonTotal - discount)
     */
    public function createFinance(
        int $bookingId,
        float $packagePrice,
        float $addonTotal,
        float $discount = 0.0
    ): BookingFinance {
        $finalPrice = max(0.0, $packagePrice + $addonTotal - $discount);

        return BookingFinance::create([
            'booking_id'  => $bookingId,
            'price'       => $packagePrice,
            'discount'    => $discount,
            'final_price' => $finalPrice,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */

    /**
     * Proses pembayaran awal dalam satu transaksi yang sama.
     *
     * - Pakai $finance yang sudah ada (tidak query ulang ke DB)
     * - lockForUpdate() untuk mencegah race condition
     * - Throw PaymentExceededException jika melebihi tagihan
     *
     * @throws PaymentExceededException
     */
    public function processInitialPayment(
        int $bookingId,
        BookingFinance $finance,
        array $payment
    ): ?BookingPayment {
        if (empty($payment['amount'])) {
            return null;
        }

        $finance->refresh()->lockForUpdate();

        $totalPaid = BookingPayment::where('booking_id', $bookingId)
            ->lockForUpdate()
            ->sum('amount');

        $newTotal = $totalPaid + $payment['amount'];

        if ($newTotal > $finance->final_price) {
            throw new PaymentExceededException();
        }

        return BookingPayment::create([
            'booking_id'     => $bookingId,
            'payment_type'   => $this->resolvePaymentType($newTotal, $finance->final_price, $totalPaid),
            'amount'         => $payment['amount'],
            'payment_method' => $payment['payment_method'],
            'payment_date'   => now(),
            'proof'          => null,
            'note'           => $payment['note'] ?? null,
        ]);
    }

    /**
     * Tentukan tipe pembayaran:
     *   Pelunasan → lunas
     *   DP        → pembayaran pertama, belum lunas
     *   Partial   → pembayaran berikutnya, belum lunas
     */
    private function resolvePaymentType(
        float $newTotal,
        float $finalPrice,
        float $previousTotal
    ): string {
        return match (true) {
            $newTotal >= $finalPrice => 'Pelunasan',
            $previousTotal === 0.0  => 'DP',
            default                 => 'Partial',
        };
    }


    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    */

    /**
     * Catat aktivitas ke tabel booking_histories (audit trail).
     */
    public function recordHistory(
        int $bookingId,
        int $userId,
        string $activity,
        string $description
    ): BookingHistory {
        return BookingHistory::create([
            'booking_id'  => $bookingId,
            'user_id'     => $userId,
            'activity'    => $activity,
            'description' => $description,
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Package Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Ambil job package milik user tertentu.
     * Validasi kepemilikan via relasi jobType → user_id.
     */
    public function findPackageForUser(int $packageId, int $userId): ?JobPackage
    {
        return JobPackage::with('jobType')
            ->where('id', $packageId)
            ->whereHas('jobType', fn ($q) => $q->where('user_id', $userId))
            ->first();
    }
}