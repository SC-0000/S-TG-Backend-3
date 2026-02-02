<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // assessment_lesson junction table
        Schema::create('assessment_lesson', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('new_lessons')->cascadeOnDelete();
            $table->integer('order_position')->default(0);
            $table->enum('timing', ['inline', 'end_of_lesson', 'optional'])->default('end_of_lesson');
            $table->timestamps();
            
            $table->unique(['assessment_id', 'lesson_id']);
        });
        
        // assessment_module junction table
        Schema::create('assessment_module', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->enum('timing', ['pre_test', 'post_test', 'checkpoint'])->default('post_test');
            $table->timestamps();
        });
        
        // assessment_course junction table
        Schema::create('assessment_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->enum('timing', ['diagnostic', 'mid_term', 'final'])->default('final');
            $table->timestamps();
        });
        
        // Modify assessments table to add new fields
        Schema::table('assessments', function (Blueprint $table) {
            $table->enum('assessment_level', ['lesson', 'module', 'course', 'standalone'])->default('lesson')->after('lesson_id');
            $table->boolean('can_embed_in_slides')->default(false)->after('assessment_level');
        });
        
        // Modify access table to support new hierarchy (only if it exists)
        if (Schema::hasTable('access')) {
            Schema::table('access', function (Blueprint $table) {
                $table->json('course_ids')->nullable()->after('lesson_ids');
                $table->json('module_ids')->nullable()->after('course_ids');
            });
        }
    }

    public function down(): void
    {
        // Remove added columns (only if table exists)
        if (Schema::hasTable('access')) {
            Schema::table('access', function (Blueprint $table) {
                $table->dropColumn(['course_ids', 'module_ids']);
            });
        }
        
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['assessment_level', 'can_embed_in_slides']);
        });
        
        // Drop junction tables
        Schema::dropIfExists('assessment_course');
        Schema::dropIfExists('assessment_module');
        Schema::dropIfExists('assessment_lesson');
    }
};
