<?php

namespace App\Http\Controllers\Api;

use Exception;
use App\Models\User;
use App\Mail\OtpMail;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Models\PasswordResetOtp;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\RateLimiter;

class PasswordResetController extends Controller
{
    private const OTP_EXPIRY_MINUTES      = 10;
    private const VERIFIED_EXPIRY_MINUTES = 30;  // sesi verified berlaku 30 menit
    private const RESEND_COOLDOWN_SECONDS = 60;

    private const ATTEMPTS_WARN_LIMIT  = 5;
    private const ATTEMPTS_LOCK1_LIMIT = 10;
    private const ATTEMPTS_LOCK2_LIMIT = 15;

    private const LOCK1_MINUTES = 30;
    private const LOCK2_MINUTES = 60;

    private const FORGOT_MAX_ATTEMPTS  = 5;
    private const FORGOT_DECAY_MINUTES = 1;

    // Panjang reset token (random bytes -> hex/base62 string via Str::random)
    private const RESET_TOKEN_LENGTH = 64;

    /*
    |--------------------------------------------------------------------------
    | STEP 1 — Forgot Password
    | Input : email
    | Output: OTP dikirim ke email
    |
    | CATATAN PERBAIKAN: respons sekarang SELALU generik (tidak membedakan
    | email terdaftar / tidak) untuk mencegah email enumeration. Trade-off:
    | user yang salah ketik email tidak akan dapat error langsung di step
    | ini -- mereka baru akan "ketahuan" saat verifyOtp gagal 404. Ini
    | trade-off keamanan vs UX yang umum dipakai (mis. GitHub). Kalau kamu
    | lebih mengutamakan UX (langsung kasih tahu kalau email salah) dan
    | menerima risiko enumeration, silakan kembalikan ke perilaku lama.
    |--------------------------------------------------------------------------
    */

    public function forgotPassword(Request $request)
    {
        // Rate limit per IP
        $rateLimiterKey = 'forgot-password:' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimiterKey, self::FORGOT_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateLimiterKey);

            Log::warning('Forgot Password Rate Limited', [
                'ip'    => $request->ip(),
                'email' => $request->email ?? '-',
            ]);

            return ApiResponse::error(
                'Terlalu banyak permintaan. Coba lagi dalam ' . ceil($seconds / 60) . ' menit.',
                ['retry_after' => $seconds],
                429
            );
        }

        RateLimiter::hit($rateLimiterKey, self::FORGOT_DECAY_MINUTES * 60);

        // Validasi
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal', $validator->errors(), 422);
        }

        $email = strtolower(trim($request->email));
        $user  = User::where('email', $email)->first();

        // Hanya proses cooldown / kirim OTP kalau emailnya benar-benar terdaftar.
        // Untuk email tidak terdaftar: jangan buat record, jangan kirim mail,
        // tapi tetap balas dengan pesan generik yang sama di bawah.
        if ($user) {
            $existingRecord = PasswordResetOtp::where('email', $email)->first();

            if (
                $existingRecord &&
                $existingRecord->resend_available_at &&
                now()->lt($existingRecord->resend_available_at)
            ) {
                $waitSeconds = now()->diffInSeconds($existingRecord->resend_available_at);

                return ApiResponse::error(
                    'Tunggu sebelum meminta OTP baru.',
                    ['retry_after' => $waitSeconds],
                    429
                );
            }

            try {
                // Hapus record lama, buat yang baru
                PasswordResetOtp::where('email', $email)->delete();

                $otp = random_int(100000, 999999);

                PasswordResetOtp::create([
                    'email'               => $email,
                    'otp'                 => Hash::make((string) $otp),
                    'expired_at'          => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
                    'attempts'            => 0,
                    'locked_until'        => null,
                    'verified'            => false,
                    'verified_at'         => null,
                    'reset_token'         => null,
                    'resend_available_at' => now()->addSeconds(self::RESEND_COOLDOWN_SECONDS),
                ]);

                Mail::to($email)->send(new OtpMail((string) $otp));

                Log::info('OTP Sent', ['email' => $email, 'ip' => $request->ip()]);

            } catch (Exception $e) {
                Log::error('Forgot Password Error', [
                    'email'   => $email,
                    'message' => $e->getMessage(),
                ]);

                return ApiResponse::error('Gagal memproses permintaan. Coba lagi nanti.', null, 500);
            }
        } else {
            Log::info('Forgot Password - Email Not Registered', [
                'email' => $email,
                'ip'    => $request->ip(),
            ]);
        }

        // Pesan generik -- sama persis baik email terdaftar maupun tidak.
        return ApiResponse::success('Jika email terdaftar, kode OTP telah dikirimkan ke email Anda.');
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 2 — Verify OTP
    | Input : email + otp
    | Output: jika OTP benar -> verified = true di DB + reset_token (plaintext,
    |         hanya dikirim sekali di response ini) yang WAJIB dikirim balik
    |         oleh frontend saat memanggil resetPassword.
    |--------------------------------------------------------------------------
    */

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'otp'   => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal', $validator->errors(), 422);
        }

        $email  = strtolower(trim($request->email));
        $record = PasswordResetOtp::where('email', $email)->first();

        if (!$record) {
            return ApiResponse::error(
                'OTP tidak ditemukan. Silakan request OTP baru.',
                null,
                404
            );
        }

        // Cek lock SEBELUM increment — tidak boleh increment saat sedang dikunci
        if ($record->locked_until && now()->lt($record->locked_until)) {
            Log::warning('OTP Access While Locked', [
                'email'        => $email,
                'ip'           => $request->ip(),
                'locked_until' => $record->locked_until,
            ]);

            return ApiResponse::error(
                'Akses OTP dikunci sementara.',
                ['locked_until' => $record->locked_until->toIso8601String()],
                429
            );
        }

        // FIX: kalau record pernah dikunci tapi periode lock sudah lewat,
        // reset counter attempts. Tanpa ini, satu kesalahan berikutnya
        // langsung melompat ke tier lock yang lebih berat (mis. attempts
        // 11 langsung kena lock 60 menit walau baru 1x salah setelah lock
        // 30 menit berakhir).
        if ($record->locked_until && now()->gte($record->locked_until)) {
            $record->update(['attempts' => 0, 'locked_until' => null]);
            $record->refresh();
        }

        // Cek expired
        if (now()->gt($record->expired_at)) {
            $record->delete();

            return ApiResponse::error(
                'OTP sudah kadaluarsa. Silakan request OTP baru.',
                null,
                400
            );
        }

        // Verifikasi OTP
        if (!Hash::check($request->otp, $record->otp)) {

            $record->increment('attempts');
            $attempts = $record->fresh()->attempts;

            Log::warning('OTP Invalid Attempt', [
                'email'    => $email,
                'ip'       => $request->ip(),
                'attempts' => $attempts,
            ]);

            // Tier 1: masih boleh coba
            if ($attempts <= self::ATTEMPTS_WARN_LIMIT) {
                return ApiResponse::error(
                    'OTP tidak valid.',
                    ['remaining_attempts' => self::ATTEMPTS_WARN_LIMIT - $attempts],
                    400
                );
            }

            // Tier 2: lock 30 menit
            if ($attempts <= self::ATTEMPTS_LOCK1_LIMIT) {
                $record->update(['locked_until' => now()->addMinutes(self::LOCK1_MINUTES)]);

                return ApiResponse::error(
                    'Terlalu banyak percobaan. OTP dikunci selama ' . self::LOCK1_MINUTES . ' menit.',
                    null,
                    429
                );
            }

            // Tier 3: lock 60 menit
            if ($attempts <= self::ATTEMPTS_LOCK2_LIMIT) {
                $record->update(['locked_until' => now()->addMinutes(self::LOCK2_MINUTES)]);

                return ApiResponse::error(
                    'Terlalu banyak percobaan. OTP dikunci selama ' . self::LOCK2_MINUTES . ' menit.',
                    null,
                    429
                );
            }

            // Tier 4: habis total → hapus record, mulai dari awal
            Log::error('OTP Exhausted - Record Deleted', [
                'email'    => $email,
                'ip'       => $request->ip(),
                'attempts' => $attempts,
            ]);

            $record->delete();

            return ApiResponse::error(
                'OTP dibatalkan karena terlalu banyak percobaan gagal. Silakan request OTP baru.',
                null,
                429
            );
        }

        // OTP benar — generate reset token rahasia. Plaintext-nya HANYA
        // dikirim sekali di response ini; yang disimpan di DB cuma hash-nya.
        $resetTokenPlain = Str::random(self::RESET_TOKEN_LENGTH);
        $now             = now();

        $record->update([
            'verified'    => true,
            'verified_at' => $now,
            'otp'         => null,   // bersihkan OTP, tidak bisa dipakai ulang
            'reset_token' => Hash::make($resetTokenPlain),
        ]);

        Log::info('OTP Verified', ['email' => $email, 'ip' => $request->ip()]);

        return ApiResponse::success(
            'OTP valid. Silakan ganti password Anda.',
            [
                // Token rahasia yang wajib dikirim balik di resetPassword.
                'reset_token' => $resetTokenPlain,
                // ISO 8601 (dengan offset/Z) supaya `new Date()` di JS tidak
                // salah interpretasi sebagai waktu lokal browser.
                'verified_until' => $now->copy()
                    ->addMinutes(self::VERIFIED_EXPIRY_MINUTES)
                    ->toIso8601String(),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Resend OTP
    | Delegate ke forgotPassword — rate limit & cooldown sudah ada di sana
    |--------------------------------------------------------------------------
    */

    public function resendOtp(Request $request)
    {
        return $this->forgotPassword($request);
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 3 — Reset Password
    | Input : email + reset_token + password + password_confirmation
    |
    | FIX KEAMANAN: sebelumnya endpoint ini hanya mengecek flag boolean
    | `verified` di DB yang dicocokkan lewat email — artinya siapa pun yang
    | tahu email korban bisa langsung reset password korban dalam jendela
    | 30 menit tanpa pernah punya OTP-nya. Sekarang wajib menyertakan
    | `reset_token` rahasia yang hanya didapat client yang benar-benar lolos
    | verifyOtp.
    |--------------------------------------------------------------------------
    */

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'       => 'required|email|max:255',
            'reset_token' => 'required|string',
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validasi gagal', $validator->errors(), 422);
        }

        $email     = strtolower(trim($request->email));
        $otpRecord = PasswordResetOtp::where('email', $email)->first();

        // Pastikan sudah melewati step verifyOtp dengan benar
        if (!$otpRecord || !$otpRecord->verified || !$otpRecord->reset_token) {
            return ApiResponse::error(
                'Sesi tidak valid. Silakan verifikasi OTP terlebih dahulu.',
                null,
                403
            );
        }

        // Cek apakah sesi verified sudah expired
        if (
            !$otpRecord->verified_at ||
            now()->gt($otpRecord->verified_at->copy()->addMinutes(self::VERIFIED_EXPIRY_MINUTES))
        ) {
            $otpRecord->delete();

            return ApiResponse::error(
                'Sesi reset password telah kadaluarsa. Silakan ulangi proses dari awal.',
                null,
                403
            );
        }

        // Cocokkan reset_token yang dikirim client dengan hash di DB
        if (!Hash::check($request->reset_token, $otpRecord->reset_token)) {
            Log::warning('Reset Password - Invalid Token', [
                'email' => $email,
                'ip'    => $request->ip(),
            ]);

            return ApiResponse::error(
                'Sesi tidak valid. Silakan verifikasi OTP terlebih dahulu.',
                null,
                403
            );
        }

        // Cari user
        $user = User::where('email', $email)->first();

        if (!$user) {
            return ApiResponse::error('User tidak ditemukan.', null, 404);
        }

        // Ganti password
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Nonaktifkan semua sesi aktif (aktifkan jika pakai Sanctum)
        // $user->tokens()->delete();

        // Bersihkan record OTP
        $otpRecord->delete();

        Log::info('Password Reset Successful', [
            'email' => $email,
            'ip'    => $request->ip(),
        ]);

        return ApiResponse::success('Password berhasil direset. Silakan login dengan password baru Anda.');
    }
}