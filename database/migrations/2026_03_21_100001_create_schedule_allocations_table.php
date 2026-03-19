<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained('teacher_profiles')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->tinyInteger('day_of_week'); // 1=Mon ... 7=Sun
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('allocation_type', ['fixed', 'bookable'])->default('bookable');
            $table->string('recurrence', 20)->nullable(); // 'weekly', 'biweekly', null (one-off)
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('title')->nullable();
            $table->integer('max_participants')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamps();

            $table->index(['teacher_profile_id', 'day_of_week']);
            $table->index(['service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_allocations');
    }
};
