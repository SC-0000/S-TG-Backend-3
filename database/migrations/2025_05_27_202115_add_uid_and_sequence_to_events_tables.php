<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUidAndSequenceToEventsTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add to lessons
        Schema::table('lessons', function (Blueprint $table) {
            $table->char('uid', 36)
                  ->nullable()
                  ->unique()
                  ->after('id');
            $table->unsignedInteger('sequence')
                  ->default(0)
                  ->after('uid');
        });

        // Add to assessments
        Schema::table('assessments', function (Blueprint $table) {
            $table->char('uid', 36)
                  ->nullable()
                  ->unique()
                  ->after('id');
            $table->unsignedInteger('sequence')
                  ->default(0)
                  ->after('uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['sequence', 'uid']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['sequence', 'uid']);
        });
    }
}
