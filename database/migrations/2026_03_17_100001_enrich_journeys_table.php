<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journeys', function (Blueprint $table) {
            $table->string('exam_board', 255)->nullable()->after('exam_end_date');
            $table->string('curriculum_level', 100)->nullable()->after('exam_board');
            $table->json('year_groups')->nullable()->after('curriculum_level');
            $table->json('exam_dates')->nullable()->after('year_groups');
            $table->string('exam_website_url', 500)->nullable()->after('exam_dates');
            $table->string('specification_reference', 500)->nullable()->after('exam_website_url');
        });
    }

    public function down(): void
    {
        Schema::table('journeys', function (Blueprint $table) {
            $table->dropColumn([
                'exam_board',
                'curriculum_level',
                'year_groups',
                'exam_dates',
                'exam_website_url',
                'specification_reference',
            ]);
        });
    }
};
