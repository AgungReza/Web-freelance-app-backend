<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Migration: tambah kolom reset_token
|--------------------------------------------------------------------------
| Dibutuhkan supaya sesi "verified" tidak hanya dijaga oleh flag boolean
| `verified` di DB (yang bisa dipakai siapa saja yang tahu email korban),
| tapi juga oleh secret token yang hanya dimiliki client yang benar-benar
| menyelesaikan verifyOtp.
|
| Kita simpan HASH dari token, bukan plaintext-nya — sama prinsipnya
| dengan kolom `otp`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {
            $table->string('reset_token')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {
            $table->dropColumn('reset_token');
        });
    }
};