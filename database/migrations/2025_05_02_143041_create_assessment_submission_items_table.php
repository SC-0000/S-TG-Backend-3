<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_submission_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('submission_id')
                  ->constrained('assessment_submissions')
                  ->cascadeOnDelete();

            $table->foreignId('question_id')
                  ->constrained('assessment_questions')
                  ->cascadeOnDelete();

            $table->json('answer');
            $table->boolean('is_correct');
            $table->unsignedInteger('marks_awarded')->default(0);
            $table->unsignedInteger('time_spent')->nullable(); // seconds

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_submission_items');
    }
};
