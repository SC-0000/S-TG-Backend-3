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
        // AI Upload Sessions - tracks bulk upload jobs
        Schema::create('ai_upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            
            // Content type being generated
            $table->enum('content_type', [
                'question',
                'assessment', 
                'course',
                'module',
                'lesson',
                'slide',
                'article'
            ]);
            
            // Session status
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled',
                'review_pending',
                'approved',
                'rejected'
            ])->default('pending');
            
            // Input data from user
            $table->text('user_prompt')->nullable();
            $table->json('input_settings')->nullable(); // year_group, category, difficulty, etc.
            $table->json('source_data')->nullable(); // file contents, URL data, etc.
            $table->string('source_type')->default('prompt'); // prompt, text, file, url
            
            // AI processing settings
            $table->decimal('quality_threshold', 3, 2)->default(0.85);
            $table->integer('max_iterations')->default(10);
            $table->integer('early_stop_patience')->default(3);
            
            // Processing state
            $table->integer('current_iteration')->default(0);
            $table->decimal('current_quality_score', 3, 2)->nullable();
            $table->integer('items_generated')->default(0);
            $table->integer('items_approved')->default(0);
            $table->integer('items_rejected')->default(0);
            
            // Error tracking
            $table->text('error_message')->nullable();
            $table->json('validation_errors')->nullable();
            
            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('processing_time_seconds')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable(); // model used, tokens consumed, etc.
            
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['organization_id', 'content_type']);
            $table->index('status');
        });

        // AI Upload Proposals - individual items generated
        Schema::create('ai_upload_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_upload_sessions')->cascadeOnDelete();
            
            // Content type for this specific item
            $table->enum('content_type', [
                'question',
                'assessment',
                'course',
                'module',
                'lesson',
                'slide',
                'article'
            ]);
            
            // Status of this proposal
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'modified',
                'uploaded'
            ])->default('pending');
            
            // The generated content
            $table->json('proposed_data'); // Full model data ready to insert
            $table->json('original_data')->nullable(); // Original AI response before modifications
            
            // Validation
            $table->boolean('is_valid')->default(false);
            $table->json('validation_errors')->nullable();
            $table->decimal('quality_score', 3, 2)->nullable();
            $table->json('quality_metrics')->nullable(); // detailed quality breakdown
            
            // Hierarchical relationships for scaffolding
            $table->unsignedBigInteger('parent_proposal_id')->nullable();
            $table->string('parent_type')->nullable(); // course, module, lesson
            $table->integer('order_position')->default(0);
            
            // After upload
            $table->string('created_model_type')->nullable(); // App\Models\Question, etc.
            $table->unsignedBigInteger('created_model_id')->nullable();
            
            // User modifications
            $table->json('user_modifications')->nullable();
            $table->foreignId('modified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('modified_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['session_id', 'content_type']);
            $table->index(['session_id', 'status']);
            $table->index('parent_proposal_id');
        });

        // AI Upload Logs - detailed processing logs
        Schema::create('ai_upload_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_upload_sessions')->cascadeOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained('ai_upload_proposals')->cascadeOnDelete();
            
            // Log type
            $table->enum('level', ['debug', 'info', 'warning', 'error']);
            $table->string('action'); // generate, validate, refine, upload, etc.
            
            // Log content
            $table->text('message');
            $table->json('context')->nullable(); // Additional data
            
            // AI interaction details
            $table->string('ai_model')->nullable();
            $table->integer('tokens_input')->nullable();
            $table->integer('tokens_output')->nullable();
            $table->decimal('cost_usd', 8, 6)->nullable();
            $table->integer('duration_ms')->nullable();
            
            $table->timestamp('created_at');
            
            // Index
            $table->index(['session_id', 'level']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_upload_logs');
        Schema::dropIfExists('ai_upload_proposals');
        Schema::dropIfExists('ai_upload_sessions');
    }
};
