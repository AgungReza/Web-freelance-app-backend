<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Migration: ubah kolom otp jadi nullable
|--------------------------------------------------------------------------
| Setelah OTP berhasil diverifikasi, controller sengaja set kolom `otp`
| menjadi NULL (supaya hash OTP yang sudah terpakai tidak tersisa di DB
| dan tidak bisa dipakai ulang). Kalau kolom ini masih NOT NULL dari
| migration awal, update tersebut akan gagal dengan error:
|   SQLSTATE[23000]: Column 'otp' cannot be null
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {
            $table->string('otp')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {
            // Hati-hati: kalau sudah ada baris dengan otp = NULL saat rollback,
            // perintah ini akan gagal karena kembali ke NOT NULL.
            // Bersihkan/isi dulu baris yang NULL sebelum rollback kalau perlu.
            $table->string('otp')->nullable(false)->change();
        });
    }
};