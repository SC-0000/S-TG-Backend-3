<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_lesson_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            
            // Status
            $table->enum('status', ['invited', 'joined', 'left', 'kicked'])->default('invited');
            $table->enum('connection_status', ['connected', 'disconnected', 'reconnecting'])->default('disconnected');
            
            // Timing
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            
            // Current State
            $table->foreignId('current_slide_id')->nullable()->constrained('lesson_slides')->nullOnDelete();
            $table->boolean('audio_muted')->default(false);
            $table->boolean('video_off')->default(true);
            
            // Engagement
            $table->json('interaction_data')->nullable();
            
            // Connection Quality
            $table->json('connection_metrics')->nullable();
            
            $table->timestamps();
            
            $table->unique(['live_lesson_session_id', 'child_id']);
            $table->index(['live_lesson_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_session_participants');
    }
};
