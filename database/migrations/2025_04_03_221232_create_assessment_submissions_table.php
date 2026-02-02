<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assessment_id')
                  ->constrained()
                  ->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()            // or child_id if you track children separately
                  ->constrained()
                  ->cascadeOnDelete();

            $table->unsignedInteger('retake_number')->default(1);

            $table->unsignedInteger('total_marks')->nullable();
            $table->unsignedInteger('marks_obtained')->nullable();

            $table->enum('status', ['pending','graded','late'])->default('pending');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->json('meta')->nullable();       // IP, UA, etc.

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_submissions');
    }
};
