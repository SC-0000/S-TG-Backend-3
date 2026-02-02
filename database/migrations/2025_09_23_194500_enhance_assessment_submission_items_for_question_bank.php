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
        Schema::table('assessment_submission_items', function (Blueprint $table) {
            // Add columns to support both inline and bank questions
            $table->enum('question_type', ['bank', 'inline'])->after('submission_id');
            $table->unsignedBigInteger('bank_question_id')->nullable()->after('question_type');
            $table->integer('inline_question_index')->nullable()->after('bank_question_id');
            
            // Store complete question snapshot at submission time
            $table->json('question_data')->nullable()->after('inline_question_index');
            
            // Enhanced grading metadata
            $table->json('grading_metadata')->nullable()->after('question_data');
            
            // Enhanced feedback field
            $table->text('detailed_feedback')->nullable()->after('marks_awarded');
            
            // Add foreign key for bank questions
            $table->foreign('bank_question_id')->references('id')->on('questions')->onDelete('set null');
            
            // Add index for better performance with custom names
            $table->index(['question_type', 'bank_question_id'], 'asi_qtype_bank_id_idx');
            $table->index(['question_type', 'inline_question_index'], 'asi_qtype_inline_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessment_submission_items', function (Blueprint $table) {
            $table->dropForeign(['bank_question_id']);
            $table->dropIndex('asi_qtype_bank_id_idx');
            $table->dropIndex('asi_qtype_inline_idx');
            
            $table->dropColumn([
                'question_type',
                'bank_question_id', 
                'inline_question_index',
                'question_data',
                'grading_metadata',
                'detailed_feedback'
            ]);
        });
    }
};
