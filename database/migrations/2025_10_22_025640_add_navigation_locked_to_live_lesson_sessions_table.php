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
            $table->boolean('navigation_locked')->default(false)->after('pacing_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_lesson_sessions', function (Blueprint $table) {
            $table->dropColumn('navigation_locked');
        });
    }
};
