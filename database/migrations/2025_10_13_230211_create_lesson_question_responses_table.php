<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_question_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_progress_id')->constrained('lesson_progress')->cascadeOnDelete();
            $table->foreignId('slide_id')->constrained('lesson_slides')->cascadeOnDelete();
            $table->string('block_id'); // UUID within slide
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            
            // Response
            $table->json('answer_data'); // Student's answer
            $table->boolean('is_correct')->nullable();
            $table->decimal('score_earned', 5, 2)->nullable();
            $table->decimal('score_possible', 5, 2);
            
            // Attempts
            $table->integer('attempt_number')->default(1);
            $table->integer('time_spent_seconds')->default(0);
            
            // Feedback
            $table->text('feedback')->nullable();
            $table->json('hints_used')->nullable();
            
            // Timestamps
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            
            $table->index(['child_id', 'lesson_progress_id']);
            $table->index(['question_id', 'is_correct']);
            $table->index(['slide_id', 'block_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_question_responses');
    }
};
