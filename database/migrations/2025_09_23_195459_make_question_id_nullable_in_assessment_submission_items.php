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
        Schema::table('assessment_submission_items', function (Blueprint $table) {
            // Make question_id nullable for bank questions
            $table->unsignedBigInteger('question_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessment_submission_items', function (Blueprint $table) {
            // Revert to non-nullable (but this might fail if there are NULL values)
            $table->unsignedBigInteger('question_id')->nullable(false)->change();
        });
    }
};
