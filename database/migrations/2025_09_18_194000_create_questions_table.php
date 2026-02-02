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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('title');
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();
            $table->string('question_type'); // flexible string instead of enum
            $table->json('question_data'); // flexible schema per question type
            $table->json('answer_schema'); // defines correct answers/rubrics
            $table->integer('difficulty_level')->default(5); // 1-10 scale
            $table->integer('estimated_time_minutes')->nullable();
            $table->decimal('marks', 8, 2)->default(1);
            $table->json('ai_metadata')->nullable(); // difficulty, discrimination, reading_age
            $table->text('image_description')->nullable(); // for AI processing
            $table->json('hints')->nullable(); // array of hints
            $table->json('solutions')->nullable(); // step-by-step solutions
            $table->json('tags')->nullable(); // array of tags
            $table->integer('version')->default(1);
            $table->string('status')->default('draft'); // draft, active, retired, under_review
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes
            $table->index('organization_id');
            $table->index('question_type');
            $table->index('category');
            $table->index(['status', 'organization_id']);
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
