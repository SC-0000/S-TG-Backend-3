<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Current DB has: 'scheduled', 'active', 'paused', 'ended', 'cancelled'
        // We want: 'scheduled', 'live', 'paused', 'ended', 'cancelled'
        
        // Step 1: Expand the ENUM to include both 'active' and 'live'
        DB::statement("ALTER TABLE live_lesson_sessions MODIFY COLUMN status ENUM('scheduled', 'live', 'active', 'paused', 'ended', 'cancelled') NOT NULL DEFAULT 'scheduled'");

        // Step 2: Update existing 'active' rows to 'live'
        DB::table('live_lesson_sessions')
            ->where('status', 'active')
            ->update(['status' => 'live']);

        // Step 3: Remove 'active' from the ENUM (only keep 'live')
        DB::statement("ALTER TABLE live_lesson_sessions MODIFY COLUMN status ENUM('scheduled', 'live', 'paused', 'ended', 'cancelled') NOT NULL DEFAULT 'scheduled'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to 'active'
        
        // Step 1: Expand ENUM to include both
        DB::statement("ALTER TABLE live_lesson_sessions MODIFY COLUMN status ENUM('scheduled', 'live', 'active', 'paused', 'ended', 'cancelled') NOT NULL DEFAULT 'scheduled'");
        
        // Step 2: Convert 'live' back to 'active'
        DB::table('live_lesson_sessions')
            ->where('status', 'live')
            ->update(['status' => 'active']);

        // Step 3: Remove 'live' from enum
        DB::statement("ALTER TABLE live_lesson_sessions MODIFY COLUMN status ENUM('scheduled', 'active', 'paused', 'ended', 'cancelled') NOT NULL DEFAULT 'scheduled'");
    }
};
