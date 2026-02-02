<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename lessons table to live_sessions
        Schema::rename('lessons', 'live_sessions');
        
        // Rename pivot table
        Schema::rename('child_lesson', 'child_live_session');
        
        // Add new columns to live_sessions
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->after('service_id')->constrained('new_lessons')->nullOnDelete();
            $table->enum('pacing_mode', ['self_paced', 'teacher_led'])->default('teacher_led')->after('status');
        });
    }

    public function down(): void
    {
        // Remove new columns
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->dropColumn(['lesson_id', 'pacing_mode']);
        });
        
        // Rename back
        Schema::rename('child_live_session', 'child_lesson');
        Schema::rename('live_sessions', 'lessons');
    }
};
