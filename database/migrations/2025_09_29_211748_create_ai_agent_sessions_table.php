<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->enum('agent_type', ['tutor', 'grading_review', 'progress', 'hint'])
                  ->comment('Type of AI agent handling this session');
            $table->json('context_summary')->nullable()
                  ->comment('Compressed context for efficient retrieval');
            $table->longText('memory_embeddings')->nullable()
                  ->comment('Vector embeddings for similarity search');
            $table->json('session_metadata')->nullable()
                  ->comment('Additional session data and preferences');
            $table->timestamp('last_interaction')->nullable()
                  ->comment('When this session was last active');
            $table->boolean('is_active')->default(true)
                  ->comment('Whether this session is currently active');
            $table->timestamps();

            // Indexes for performance
            $table->index(['child_id', 'agent_type']);
            $table->index(['child_id', 'is_active']);
            $table->index('last_interaction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agent_sessions');
    }
};
