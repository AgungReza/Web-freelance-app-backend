<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Migration: Tambah kolom verified_at & resend_available_at
|--------------------------------------------------------------------------
|
| Kolom ini dibutuhkan untuk:
|   - verified_at        → FIX #4: expired window setelah OTP verified
|   - resend_available_at → FIX #5: cooldown sebelum boleh resend OTP
|
| Jalankan dengan:
|   php artisan migrate
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {

            // Waktu kapan OTP berhasil diverifikasi
            $table->timestamp('verified_at')
                  ->nullable()
                  ->after('verified');

            // Waktu kapan user boleh resend OTP berikutnya
            $table->timestamp('resend_available_at')
                  ->nullable()
                  ->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'resend_available_at']);
        });
    }
};