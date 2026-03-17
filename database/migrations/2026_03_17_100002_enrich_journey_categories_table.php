<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journey_categories', function (Blueprint $table) {
            $table->text('ai_context')->nullable()->after('description');
            $table->json('learning_objectives')->nullable()->after('ai_context');
            $table->json('key_topics')->nullable()->after('learning_objectives');
            $table->unsignedSmallInteger('difficulty_weighting')->nullable()->after('key_topics');
            $table->decimal('estimated_hours', 5, 1)->nullable()->after('difficulty_weighting');
            $table->string('specification_reference', 500)->nullable()->after('estimated_hours');
            $table->text('parent_summary')->nullable()->after('specification_reference');
            $table->unsignedInteger('sort_order')->default(0)->after('parent_summary');
        });
    }

    public function down(): void
    {
        Schema::table('journey_categories', function (Blueprint $table) {
            $table->dropColumn([
                'ai_context',
                'learning_objectives',
                'key_topics',
                'difficulty_weighting',
                'estimated_hours',
                'specification_reference',
                'parent_summary',
                'sort_order',
            ]);
        });
    }
};
