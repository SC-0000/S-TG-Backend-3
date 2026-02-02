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
        Schema::table('live_session_participants', function (Blueprint $table) {
            $table->boolean('hand_raised')->default(false)->after('status');
            $table->timestamp('hand_raised_at')->nullable()->after('hand_raised');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_session_participants', function (Blueprint $table) {
            $table->dropColumn(['hand_raised', 'hand_raised_at']);
        });
    }
};
