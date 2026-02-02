<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin tasks
        Schema::table('admin_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('admin_tasks', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'status']);
            }
        });

        // Feedbacks
        Schema::table('feedbacks', function (Blueprint $table) {
            if (!Schema::hasColumn('feedbacks', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'status']);
            }
        });

        // Parent feedbacks
        Schema::table('parent_feedbacks', function (Blueprint $table) {
            if (!Schema::hasColumn('parent_feedbacks', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'status']);
            }
        });

        // Products
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'category']);
            }
        });

        // Journeys
        Schema::table('journeys', function (Blueprint $table) {
            if (!Schema::hasColumn('journeys', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'title']);
            }
        });

        // Journey categories
        Schema::table('journey_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('journey_categories', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('journey_id')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'journey_id']);
            }
        });

        // Slides
        Schema::table('slides', function (Blueprint $table) {
            if (!Schema::hasColumn('slides', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('slide_id')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'status']);
            }
        });

        // Alerts
        Schema::table('alerts', function (Blueprint $table) {
            if (!Schema::hasColumn('alerts', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('alert_id')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'type', 'priority']);
            }
        });

        // Testimonials
        Schema::table('testimonials', function (Blueprint $table) {
            if (!Schema::hasColumn('testimonials', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('TestimonialID')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'Status']);
            }
        });

        // Milestones
        Schema::table('milestones', function (Blueprint $table) {
            if (!Schema::hasColumn('milestones', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('MilestoneID')->constrained()->nullOnDelete();
                $table->index(['organization_id', 'Date']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('admin_tasks', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'status']);
            }
        });

        Schema::table('feedbacks', function (Blueprint $table) {
            if (Schema::hasColumn('feedbacks', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'status']);
            }
        });

        Schema::table('parent_feedbacks', function (Blueprint $table) {
            if (Schema::hasColumn('parent_feedbacks', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'status']);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'category']);
            }
        });

        Schema::table('journeys', function (Blueprint $table) {
            if (Schema::hasColumn('journeys', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'title']);
            }
        });

        Schema::table('journey_categories', function (Blueprint $table) {
            if (Schema::hasColumn('journey_categories', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'journey_id']);
            }
        });

        Schema::table('slides', function (Blueprint $table) {
            if (Schema::hasColumn('slides', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'status']);
            }
        });

        Schema::table('alerts', function (Blueprint $table) {
            if (Schema::hasColumn('alerts', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'type', 'priority']);
            }
        });

        Schema::table('testimonials', function (Blueprint $table) {
            if (Schema::hasColumn('testimonials', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'Status']);
            }
        });

        Schema::table('milestones', function (Blueprint $table) {
            if (Schema::hasColumn('milestones', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
                $table->dropIndex(['organization_id', 'Date']);
            }
        });
    }
};
