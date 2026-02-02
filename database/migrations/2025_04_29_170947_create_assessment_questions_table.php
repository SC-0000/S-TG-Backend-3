<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssessmentQuestionsTable extends Migration
{
    public function up()
    {
        Schema::create('assessment_questions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assessment_id'); // FK to assessments(id)
            $table->text('question_text'); // The actual question.
            $table->string('question_image')->nullable(); // Optional image file path.
            $table->enum('type', ['mcq', 'short_answer', 'essay']);
            $table->json('options')->nullable(); // Possible answers (for MCQs).
            $table->json('correct_answer')->nullable(); // Correct answer(s).
            $table->integer('marks'); // Marks allocated.
            $table->timestamps();

            $table->foreign('assessment_id')->references('id')->on('assessments')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('assessment_questions');
    }
}

