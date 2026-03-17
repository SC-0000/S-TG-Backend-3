<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('booking_mode', 30)->default('none')->after('_type')
                ->comment('fixed_schedule|flexible_booking|self_paced|none');
            $table->unsignedInteger('session_duration_minutes')->nullable()->after('schedule');
            $table->unsignedInteger('max_participants')->nullable()->after('session_duration_minutes');
            $table->json('teacher_ids')->nullable()->after('instructor_id')
                ->comment('Eligible teachers for flexible booking');
            $table->boolean('allow_recurring')->default(false)->after('auto_attendance');
            $table->unsignedInteger('cancellation_hours')->nullable()->after('allow_recurring');
            $table->unsignedInteger('credits_per_purchase')->nullable()->after('quantity_allowed_per_child')
                ->comment('If set, purchase grants credits instead of direct access');

            $table->index('booking_mode');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['booking_mode']);
            $table->dropColumn([
                'booking_mode',
                'session_duration_minutes',
                'max_participants',
                'teacher_ids',
                'allow_recurring',
                'cancellation_hours',
                'credits_per_purchase',
            ]);
        });
    }
};
