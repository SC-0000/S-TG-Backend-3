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
        Schema::create('agent_memory_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->enum('context_type', ['assessment', 'lesson', 'struggle_pattern', 'strength', 'question_review', 'learning_preference'])
                  ->comment('Type of context being stored');
            $table->longText('embedding')->nullable()
                  ->comment('Vector embedding for similarity search');
            $table->json('content')
                  ->comment('The actual context data');
            $table->decimal('relevance_score', 5, 4)->default(1.0000)
                  ->comment('Relevance score for this context');
            $table->timestamp('last_accessed')->nullable()
                  ->comment('When this context was last retrieved');
            $table->integer('access_count')->default(0)
                  ->comment('How many times this context has been accessed');
            $table->json('metadata')->nullable()
                  ->comment('Additional metadata about this context');
            $table->timestamps();

            // Indexes for performance
            $table->index(['child_id', 'context_type']);
            $table->index(['child_id', 'relevance_score']);
            $table->index('last_accessed');
            
            // Note: Vector similarity search will be implemented in Phase 3 with specialized indexing
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_memory_contexts');
    }
};
