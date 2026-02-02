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
        Schema::create('ai_grading_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_submission_item_id')->constrained('assessment_submission_items')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->enum('flag_reason', [
                'incorrect_grade',
                'unfair_scoring', 
                'missed_content',
                'ai_misunderstood',
                'partial_credit_issue',
                'other'
            ]);
            $table->text('student_explanation');
            $table->enum('status', ['pending', 'resolved', 'dismissed'])->default('pending');
            $table->text('admin_response')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->decimal('original_grade', 8, 2);
            $table->decimal('final_grade', 8, 2)->nullable();
            $table->boolean('grade_changed')->default(false);
            $table->timestamps();

            // Indexes for performance
            $table->index(['status']);
            $table->index(['assessment_submission_item_id']);
            $table->index(['user_id']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_grading_flags');
    }
};
