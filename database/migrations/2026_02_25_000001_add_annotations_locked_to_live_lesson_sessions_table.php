<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_lesson_sessions', function (Blueprint $table) {
            $table->boolean('annotations_locked')->default(true)->after('navigation_locked');
        });
    }

    public function down(): void
    {
        Schema::table('live_lesson_sessions', function (Blueprint $table) {
            $table->dropColumn('annotations_locked');
        });
    }
};
