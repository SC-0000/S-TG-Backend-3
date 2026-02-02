<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('new_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 50)->unique();
            
            // Basic Info
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('order_position')->default(0);
            
            // Type & Delivery
            $table->enum('lesson_type', ['interactive', 'video', 'reading', 'practice', 'assessment'])->default('interactive');
            $table->enum('delivery_mode', ['self_paced', 'live_interactive', 'hybrid'])->default('self_paced');
            
            // Status
            $table->enum('status', ['draft', 'review', 'live', 'archived'])->default('draft');
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->integer('estimated_minutes')->default(0);
            
            // Completion Rules
            $table->json('completion_rules')->nullable();
            
            // AI Features
            $table->boolean('enable_ai_help')->default(true);
            $table->boolean('enable_tts')->default(true);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['module_id', 'order_position']);
            $table->index(['delivery_mode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('new_lessons');
    }
};
