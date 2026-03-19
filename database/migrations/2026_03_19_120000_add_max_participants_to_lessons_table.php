<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_participants')->nullable()->after('lesson_type')
                ->comment('Per-session capacity cap; overrides service.max_participants when set');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn('max_participants');
        });
    }
};
