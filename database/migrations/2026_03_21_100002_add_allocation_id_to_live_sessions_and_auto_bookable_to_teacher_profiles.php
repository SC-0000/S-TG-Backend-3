<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->foreignId('allocation_id')
                ->nullable()
                ->after('service_id')
                ->constrained('schedule_allocations')
                ->nullOnDelete();
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->boolean('auto_bookable')->default(false)->after('max_hours_per_week');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allocation_id');
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn('auto_bookable');
        });
    }
};
