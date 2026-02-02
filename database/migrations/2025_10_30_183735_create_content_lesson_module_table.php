<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop if exists (in case of partial migration)
        Schema::dropIfExists('content_lesson_module');
        
        // Create the pivot table
        Schema::create('content_lesson_module', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_lesson_id')->constrained('new_lessons')->onDelete('cascade');
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->integer('order_position')->default(0);
            $table->timestamps();
            
            $table->unique(['content_lesson_id', 'module_id']);
        });

        // Migrate existing data from new_lessons.module_id to pivot table
        DB::statement('
            INSERT INTO content_lesson_module (content_lesson_id, module_id, order_position, created_at, updated_at)
            SELECT id, module_id, order_position, created_at, updated_at
            FROM new_lessons
            WHERE module_id IS NOT NULL
        ');

        // Drop the module_id column from new_lessons
        Schema::table('new_lessons', function (Blueprint $table) {
            $table->dropForeign(['module_id']);
            $table->dropColumn('module_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add module_id column to new_lessons
        Schema::table('new_lessons', function (Blueprint $table) {
            $table->foreignId('module_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Migrate data back from pivot table to new_lessons
        // Note: This only restores the first module relationship for each lesson
        DB::statement('
            UPDATE new_lessons cl
            INNER JOIN (
                SELECT content_lesson_id, MIN(module_id) as module_id
                FROM content_lesson_module
                GROUP BY content_lesson_id
            ) clm ON cl.id = clm.content_lesson_id
            SET cl.module_id = clm.module_id
        ');

        // Drop the pivot table
        Schema::dropIfExists('content_lesson_module');
    }
};
