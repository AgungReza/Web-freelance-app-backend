<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {

            $table->timestamp('locked_until')
                ->nullable()
                ->after('attempts');

        });
    }

    public function down(): void
    {
        Schema::table('password_reset_otps', function (Blueprint $table) {

            $table->dropColumn('locked_until');

        });
    }
};