<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('new_lessons')->cascadeOnDelete();
            $table->foreignId('slide_id')->constrained('lesson_slides')->cascadeOnDelete();
            $table->string('block_id'); // UUID reference within slide
            
            // File Info
            $table->string('file_path');
            $table->enum('file_type', ['image', 'pdf', 'audio', 'video', 'document'])->default('image');
            $table->integer('file_size_kb')->default(0);
            $table->string('original_filename')->nullable();
            
            // Grading Status
            $table->enum('status', ['pending', 'reviewing', 'graded', 'returned'])->default('pending');
            $table->decimal('score', 5, 2)->nullable();
            $table->json('rubric_data')->nullable();
            
            // Feedback
            $table->text('feedback')->nullable();
            $table->string('feedback_audio')->nullable();
            $table->json('annotations')->nullable();
            
            // AI Analysis
            $table->json('ai_analysis')->nullable();
            
            // Review
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['child_id', 'lesson_id']);
            $table->index(['status', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_uploads');
    }
};
