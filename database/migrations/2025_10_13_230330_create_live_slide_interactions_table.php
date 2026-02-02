<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_slide_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_lesson_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slide_id')->constrained('lesson_slides')->cascadeOnDelete();
            $table->foreignId('child_id')->nullable()->constrained()->nullOnDelete();
            
            // Interaction Type
            $table->enum('interaction_type', [
                'poll_response', 'whiteboard_draw', 'question', 
                'annotation', 'raised_hand', 'emoji_reaction'
            ]);
            
            // Data
            $table->json('data');
            
            // Metadata
            $table->boolean('is_teacher')->default(false);
            $table->boolean('visible_to_students')->default(false);
            
            $table->timestamp('created_at');
            
            $table->index(['live_lesson_session_id', 'slide_id']);
            $table->index(['child_id', 'interaction_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_slide_interactions');
    }
};
