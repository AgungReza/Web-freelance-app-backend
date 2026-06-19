<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->boolean('has_npwp')->default(false);
            $table->string('npwp', 20)->nullable()->comment('Format: XX.XXX.XXX.X-XXX.XXX');

            // Status PTKP sesuai ketentuan DJP
            $table->enum('ptkp_status', [
                'TK/0', 'TK/1', 'TK/2', 'TK/3',  // Tidak kawin
                'K/0',  'K/1',  'K/2',  'K/3',   // Kawin
            ])->default('TK/0');

            $table->timestamps();

            // Satu user hanya boleh punya satu profil pajak
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_profiles');
    }
};