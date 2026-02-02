<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->index(['lesson_id', 'date'], 'attendance_lesson_date_idx');
            $table->index(['child_id', 'date'], 'attendance_child_date_idx');
            $table->index(['status'], 'attendance_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropIndex('attendance_lesson_date_idx');
            $table->dropIndex('attendance_child_date_idx');
            $table->dropIndex('attendance_status_idx');
        });
    }
};
