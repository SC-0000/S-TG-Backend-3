<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('background_agent_runs')->nullOnDelete();
            $table->string('target_type', 100); // morph: Question, Assessment, ContentLesson, Course, JourneyCategory
            $table->unsignedBigInteger('target_id');
            $table->string('issue_type', 50); // missing_description, missing_thumbnail, etc.
            $table->string('severity', 10); // critical, warning, info
            $table->text('description');
            $table->boolean('auto_fixable')->default(false);
            $table->string('status', 20)->default('open'); // open, auto_fixed, manually_fixed, dismissed
            $table->timestamp('fixed_at')->nullable();
            $table->string('fixed_by', 50)->nullable(); // 'agent' or user_id
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['target_type', 'target_id', 'issue_type']);
            $table->index(['organization_id', 'status']);
            $table->index(['issue_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_quality_issues');
    }
};
