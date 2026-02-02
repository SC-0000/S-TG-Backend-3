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
        // Add year_group to assessments
        Schema::table('assessments', function (Blueprint $table) {
            $table->string('year_group', 50)->nullable()->after('title');
            $table->index('year_group');
        });

        // Add year_group to courses
        Schema::table('courses', function (Blueprint $table) {
            $table->string('year_group', 50)->nullable()->after('title');
            $table->index('year_group');
        });

        // Add year_group to live_sessions (old table)
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->string('year_group', 50)->nullable()->after('title');
            $table->index('year_group');
        });

        // Add year_group to new_lessons (content lessons)
        Schema::table('new_lessons', function (Blueprint $table) {
            $table->string('year_group', 50)->nullable()->after('title');
            $table->index('year_group');
        });

        // Add year_group to live_lesson_sessions
        Schema::table('live_lesson_sessions', function (Blueprint $table) {
            $table->string('year_group', 50)->nullable()->after('uid');
            $table->index('year_group');
        });

        // Add content_filters to subscriptions
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('content_filters')->nullable()->after('features');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropIndex(['year_group']);
            $table->dropColumn('year_group');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['year_group']);
            $table->dropColumn('year_group');
        });

        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropIndex(['year_group']);
            $table->dropColumn('year_group');
        });

        Schema::table('new_lessons', function (Blueprint $table) {
            $table->dropIndex(['year_group']);
            $table->dropColumn('year_group');
        });

        Schema::table('live_lesson_sessions', function (Blueprint $table) {
            $table->dropIndex(['year_group']);
            $table->dropColumn('year_group');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('content_filters');
        });
    }
};
