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
        Schema::table('live_sessions', function (Blueprint $table) {
            // Add the column after meeting_link for logical grouping
            $table->unsignedBigInteger('live_lesson_session_id')
                  ->nullable()
                  ->after('meeting_link');
            
            // Add foreign key constraint
            $table->foreign('live_lesson_session_id')
                  ->references('id')
                  ->on('live_lesson_sessions')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['live_lesson_session_id']);
            // Then drop the column
            $table->dropColumn('live_lesson_session_id');
        });
    }
};
