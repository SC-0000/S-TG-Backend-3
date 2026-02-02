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
        Schema::table('agent_memory_contexts', function (Blueprint $table) {
            // Add missing context_key column
            $table->string('context_key')->after('context_type')
                  ->comment('Unique key for this context entry');
            
            // Rename relevance_score to importance_score to match model
            $table->renameColumn('relevance_score', 'importance_score');
            
            // Update context_type enum to match model expectations
            $table->dropColumn('context_type');
        });

        // Add the new context_type enum with correct values
        Schema::table('agent_memory_contexts', function (Blueprint $table) {
            $table->enum('context_type', [
                'lesson',
                'struggle_pattern', 
                'success_pattern',
                'preference',
                'misconception',
                'progress_marker',
                'tutor_interaction',
                'hint_progression',
                'grading_dispute',
                'analysis_insight'
            ])->after('child_id')->comment('Type of context being stored');
            
            // Add unique constraint for child_id + context_type + context_key
            $table->unique(['child_id', 'context_type', 'context_key'], 'unique_context_entry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_memory_contexts', function (Blueprint $table) {
            // Remove added columns and constraints
            $table->dropUnique('unique_context_entry');
            $table->dropColumn('context_key');
            $table->renameColumn('importance_score', 'relevance_score');
            
            // Restore original context_type enum
            $table->dropColumn('context_type');
        });
        
        Schema::table('agent_memory_contexts', function (Blueprint $table) {
            $table->enum('context_type', [
                'assessment', 
                'lesson', 
                'struggle_pattern', 
                'strength', 
                'question_review', 
                'learning_preference'
            ])->after('child_id')->comment('Type of context being stored');
        });
    }
};
