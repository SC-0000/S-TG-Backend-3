<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssessmentsTable extends Migration
{
    public function up()
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title'); // Title or name of the assessment.
            $table->text('description')->nullable(); // Brief description.
            $table->unsignedBigInteger('lesson_id')->nullable(); // Links to lessons(id)
            $table->enum('type', ['mcq', 'short_answer', 'essay', 'mixed']);
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->dateTime('availability'); // When the assessment becomes available.
            $table->dateTime('deadline'); // Submission deadline.
            $table->integer('time_limit')->nullable(); // Time limit in minutes.
            $table->boolean('retake_allowed')->default(false); // Whether students can retake it.
            $table->timestamps();

            // $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('assessments');
    }
}
