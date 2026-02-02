<?php
// database/migrations/2025_06_XX_000000_add_questions_json_to_assessments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('assessments', function (Blueprint $table) {
            // JSON column to store all questions in one array
            $table->json('questions_json')->nullable();
        });
    }

    public function down()
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('questions_json');
        });
    }
};
