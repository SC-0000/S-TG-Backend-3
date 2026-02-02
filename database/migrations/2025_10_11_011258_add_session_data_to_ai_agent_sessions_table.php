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
        Schema::table('ai_agent_sessions', function (Blueprint $table) {
            $table->json('session_data')->nullable()->after('agent_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_agent_sessions', function (Blueprint $table) {
            $table->dropColumn('session_data');
        });
    }
};
