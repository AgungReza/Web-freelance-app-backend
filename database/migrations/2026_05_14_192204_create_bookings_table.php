<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            $table->string('booking_code')->unique();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // Snapshot clone dari master
            $table->string('job_type');
            $table->string('job_package');

            $table->date('day_book');

            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');

            $table->text('location');

            $table->enum('work_status', [
                'pending',
                'reserved',
                'in_progress',
                'done',
                'cancelled'
            ])->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};