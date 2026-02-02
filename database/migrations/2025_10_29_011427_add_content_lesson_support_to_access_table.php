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
        Schema::table('access', function (Blueprint $table) {
            $table->foreignId('content_lesson_id')
                  ->nullable()
                  ->after('lesson_id')
                  ->constrained('new_lessons')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access', function (Blueprint $table) {
            $table->dropForeign(['content_lesson_id']);
            $table->dropColumn('content_lesson_id');
        });
    }
};
