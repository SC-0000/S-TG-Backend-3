<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->enum('default_lesson_mode', ['online', 'in_person', 'both'])
                  ->default('both')
                  ->after('session_duration_minutes')
                  ->comment('Determines session mode: online forces online, in_person forces in-person, both lets parent choose');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('default_lesson_mode');
        });
    }
};
