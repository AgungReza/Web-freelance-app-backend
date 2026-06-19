<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->unsignedSmallInteger('tax_year')->comment('Tahun pajak, contoh: 2024');

            // ── Snapshot penghasilan ─────────────────────────────────────
            $table->decimal('bruto', 15, 2)->default(0)
                  ->comment('Total penghasilan bruto diakui (PSAK 72: booking done)');
            $table->decimal('dpp', 15, 2)->default(0)
                  ->comment('Dasar Pengenaan Pajak = bruto × 50% (norma)');

            // ── Snapshot PTKP saat dihitung ───────────────────────────────
            $table->string('ptkp_status', 10)
                  ->comment('Snapshot status PTKP saat perhitungan, misal TK/0');
            $table->decimal('ptkp_value', 15, 2)->default(0)
                  ->comment('Nilai PTKP dalam rupiah saat dihitung');

            // ── PKP & Pajak ───────────────────────────────────────────────
            $table->decimal('pkp', 15, 2)->default(0)
                  ->comment('Penghasilan Kena Pajak = DPP - PTKP (min 0)');
            $table->decimal('tax_before_correction', 15, 2)->default(0)
                  ->comment('Pajak sebelum koreksi NPWP (hasil lapisan Pasal 17)');
            $table->decimal('npwp_correction', 15, 2)->default(0)
                  ->comment('Koreksi +20% jika tidak punya NPWP');
            $table->decimal('tax_final', 15, 2)->default(0)
                  ->comment('Pajak terutang final = tax_before_correction + npwp_correction');

            // ── Kredit pajak (bukti potong 1721-VI) ──────────────────────
            $table->decimal('credit_tax', 15, 2)->default(0)
                  ->comment('Pajak yang sudah dipotong klien, diinput manual oleh user');
            $table->decimal('tax_payable', 15, 2)->default(0)
                  ->comment('Kurang bayar = tax_final - credit_tax (bisa negatif = lebih bayar)');

            // ── Breakdown lapisan pajak (JSON) ────────────────────────────
            // Contoh isi:
            // [
            //   {"layer": "sd Rp 60jt", "pkp_layer": 60000000, "rate": 5, "tax": 3000000},
            //   {"layer": "Rp 60jt - 250jt", "pkp_layer": 90000000, "rate": 15, "tax": 13500000}
            // ]
            $table->json('tax_layers')
                  ->comment('Breakdown lapisan pajak Pasal 17');

            // ── Meta ──────────────────────────────────────────────────────
            $table->decimal('effective_rate', 5, 2)->default(0)
                  ->comment('Tarif efektif = (tax_final / bruto) × 100');
            $table->timestamp('calculated_at')->nullable()
                  ->comment('Waktu terakhir dihitung');

            $table->timestamps();

            // Satu user hanya boleh punya satu record per tahun pajak
            $table->unique(['user_id', 'tax_year']);

            $table->index(['user_id', 'tax_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_records');
    }
};