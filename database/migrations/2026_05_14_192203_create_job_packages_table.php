<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_packages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_type_id')
                ->constrained('job_types')
                ->cascadeOnDelete();

            $table->string('package_name');

            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);

            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_packages');
    }
};
