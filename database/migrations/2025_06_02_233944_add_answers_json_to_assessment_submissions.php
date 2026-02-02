<?php
// database/migrations/2025_06_XX_000001_add_answers_json_to_assessment_submissions.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('assessment_submissions', function (Blueprint $table) {
            // JSON column to store each question's submitted answer
            $table->json('answers_json')->nullable();
            $table->foreignId('child_id')
                  ->nullable()
                  ->after('assessment_id')
                  ->constrained()
                  ->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::table('assessment_submissions', function (Blueprint $table) {
            $table->dropColumn('answers_json');
            $table->dropConstrainedForeignId('child_id');
        });
    }
};
