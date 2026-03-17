<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_profile_id');
            $table->date('date');
            $table->time('start_time')->nullable()->comment('Null means whole day');
            $table->time('end_time')->nullable();
            $table->string('type', 20)->default('unavailable')
                ->comment('unavailable|override');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->foreign('teacher_profile_id')
                ->references('id')->on('teacher_profiles')
                ->cascadeOnDelete();

            $table->index(['teacher_profile_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_availability_exceptions');
    }
};
