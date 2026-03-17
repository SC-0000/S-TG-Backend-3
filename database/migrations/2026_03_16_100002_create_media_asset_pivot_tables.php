<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Asset ↔ Question (question bank)
        Schema::create('media_asset_question', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->string('context')->nullable(); // e.g. "hint_image", "solution_video", "question_attachment"
            $table->unsignedInteger('order_position')->default(0);
            $table->timestamps();

            $table->unique(['media_asset_id', 'question_id', 'context'], 'maq_unique');
        });

        // Asset ↔ Content Lesson
        Schema::create('media_asset_content_lesson', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('content_lesson_id')->constrained('new_lessons')->cascadeOnDelete();
            $table->string('context')->nullable(); // e.g. "resource", "supplementary", "thumbnail"
            $table->unsignedInteger('order_position')->default(0);
            $table->timestamps();

            $table->unique(['media_asset_id', 'content_lesson_id', 'context'], 'macl_unique');
        });

        // Asset ↔ Assessment
        Schema::create('media_asset_assessment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->string('context')->nullable(); // e.g. "resource", "rubric", "answer_key"
            $table->unsignedInteger('order_position')->default(0);
            $table->timestamps();

            $table->unique(['media_asset_id', 'assessment_id', 'context'], 'maa_unique');
        });

        // Asset ↔ Journey Category (topic/subject tagging)
        Schema::create('media_asset_journey_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('journey_category_id')->constrained('journey_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['media_asset_id', 'journey_category_id'], 'majc_unique');
        });

        // Asset ↔ Course
        Schema::create('media_asset_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('context')->nullable();
            $table->unsignedInteger('order_position')->default(0);
            $table->timestamps();

            $table->unique(['media_asset_id', 'course_id', 'context'], 'mac_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_course');
        Schema::dropIfExists('media_asset_journey_category');
        Schema::dropIfExists('media_asset_assessment');
        Schema::dropIfExists('media_asset_content_lesson');
        Schema::dropIfExists('media_asset_question');
    }
};
