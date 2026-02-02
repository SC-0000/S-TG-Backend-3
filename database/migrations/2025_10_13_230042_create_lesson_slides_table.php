<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('new_lessons')->cascadeOnDelete();
            $table->string('uid', 50)->unique();
            
            // Basic Info
            $table->string('title');
            $table->integer('order_position')->default(0);
            
            // Content
            $table->json('blocks'); // Array of block objects
            
            // Template & Styling
            $table->string('template_id')->nullable();
            $table->json('layout_settings')->nullable();
            
            // Teacher Notes
            $table->text('teacher_notes')->nullable();
            
            // Timing
            $table->integer('estimated_seconds')->default(60);
            $table->boolean('auto_advance')->default(false);
            $table->integer('min_time_seconds')->nullable();
            
            // Settings
            $table->json('settings')->nullable();
            
            $table->timestamps();
            
            $table->index(['lesson_id', 'order_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_slides');
    }
};
