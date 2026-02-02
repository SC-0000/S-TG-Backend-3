<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_lesson_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('new_lessons')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 50)->unique();
            
            // Session Code
            $table->string('session_code', 10)->unique();
            
            // Status
            $table->enum('status', ['scheduled', 'live', 'completed', 'cancelled'])->default('scheduled');
            
            // Timing
            $table->timestamp('scheduled_start_time')->nullable();
            $table->timestamp('actual_start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            
            // Current State
            $table->foreignId('current_slide_id')->nullable()->constrained('lesson_slides')->nullOnDelete();
            $table->enum('pacing_mode', ['teacher_controlled', 'student_flexible'])->default('teacher_controlled');
            
            // Features
            $table->boolean('audio_enabled')->default(true);
            $table->boolean('video_enabled')->default(false);
            $table->boolean('allow_student_questions')->default(true);
            $table->boolean('whiteboard_enabled')->default(true);
            
            // Connection
            $table->json('connection_info')->nullable();
            
            // Session Data
            $table->json('session_data')->nullable();
            
            // Recording
            $table->boolean('record_session')->default(false);
            $table->string('recording_url')->nullable();
            
            $table->timestamps();
            
            $table->index(['teacher_id', 'status']);
            $table->index(['session_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_lesson_sessions');
    }
};
