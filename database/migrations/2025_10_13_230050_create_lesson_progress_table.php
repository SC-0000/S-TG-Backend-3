<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('new_lessons')->cascadeOnDelete();
            
            // Progress Status
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'abandoned'])->default('not_started');
            
            // Tracking
            $table->json('slides_viewed')->nullable(); // [slide_id, slide_id]
            $table->foreignId('last_slide_id')->nullable()->constrained('lesson_slides');
            $table->integer('completion_percentage')->default(0);
            $table->integer('time_spent_seconds')->default(0);
            
            // Scoring
            $table->decimal('score', 5, 2)->nullable();
            $table->integer('checks_passed')->default(0);
            $table->integer('checks_total')->default(0);
            
            // Questions (NEW)
            $table->integer('questions_attempted')->default(0);
            $table->integer('questions_correct')->default(0);
            $table->decimal('questions_score', 5, 2)->nullable();
            
            // Uploads
            $table->integer('uploads_submitted')->default(0);
            $table->integer('uploads_required')->default(0);
            
            // Completion
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            
            // Session Ref (if from live session)
            $table->foreignId('live_lesson_session_id')->nullable()->constrained()->nullOnDelete();
            
            $table->timestamps();
            
            $table->unique(['child_id', 'lesson_id']);
            $table->index(['lesson_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
