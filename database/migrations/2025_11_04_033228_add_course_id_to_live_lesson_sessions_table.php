<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('live_lesson_sessions', function (Blueprint $table) {
            $table->foreignId('course_id')
                ->nullable()
                ->after('lesson_id')
                ->constrained('courses')
                ->onDelete('set null');
            
            $table->index('course_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_lesson_sessions', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropIndex(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};
