<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slide_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slide_id')->constrained('lesson_slides')->cascadeOnDelete();
            $table->foreignId('lesson_progress_id')->nullable()->constrained('lesson_progress')->cascadeOnDelete();
            
            // Timing
            $table->integer('time_spent_seconds')->default(0);
            $table->integer('interactions_count')->default(0);
            
            // Engagement
            $table->json('help_requests')->nullable();
            $table->tinyInteger('confidence_rating')->nullable();
            $table->boolean('flagged_difficult')->default(false);
            
            // Block-level data
            $table->json('block_interactions')->nullable();
            
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['child_id', 'slide_id']);
            $table->index(['slide_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_interactions');
    }
};
