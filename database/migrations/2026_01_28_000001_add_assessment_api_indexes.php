<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            if (Schema::hasColumn('assessments', 'organization_id')) {
                $table->index('organization_id', 'assessments_org_id_idx');
                $table->index(['organization_id', 'status'], 'assessments_org_status_idx');
            }

            if (Schema::hasColumn('assessments', 'is_global')) {
                $table->index('is_global', 'assessments_is_global_idx');
            }

            $table->index('status', 'assessments_status_idx');
            $table->index('type', 'assessments_type_idx');
            $table->index('availability', 'assessments_availability_idx');
            $table->index('deadline', 'assessments_deadline_idx');
        });

        Schema::table('assessment_submissions', function (Blueprint $table) {
            $table->index('assessment_id', 'assessment_submissions_assessment_id_idx');
            $table->index('status', 'assessment_submissions_status_idx');
            $table->index('finished_at', 'assessment_submissions_finished_at_idx');

            if (Schema::hasColumn('assessment_submissions', 'child_id')) {
                $table->index('child_id', 'assessment_submissions_child_id_idx');
                $table->index(['child_id', 'assessment_id'], 'assessment_submissions_child_assessment_idx');
            }

            if (Schema::hasColumn('assessment_submissions', 'user_id')) {
                $table->index('user_id', 'assessment_submissions_user_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            if (Schema::hasColumn('assessments', 'organization_id')) {
                $table->dropIndex('assessments_org_id_idx');
                $table->dropIndex('assessments_org_status_idx');
            }
            if (Schema::hasColumn('assessments', 'is_global')) {
                $table->dropIndex('assessments_is_global_idx');
            }
            $table->dropIndex('assessments_status_idx');
            $table->dropIndex('assessments_type_idx');
            $table->dropIndex('assessments_availability_idx');
            $table->dropIndex('assessments_deadline_idx');
        });

        Schema::table('assessment_submissions', function (Blueprint $table) {
            $table->dropIndex('assessment_submissions_assessment_id_idx');
            $table->dropIndex('assessment_submissions_status_idx');
            $table->dropIndex('assessment_submissions_finished_at_idx');
            if (Schema::hasColumn('assessment_submissions', 'child_id')) {
                $table->dropIndex('assessment_submissions_child_id_idx');
                $table->dropIndex('assessment_submissions_child_assessment_idx');
            }
            if (Schema::hasColumn('assessment_submissions', 'user_id')) {
                $table->dropIndex('assessment_submissions_user_id_idx');
            }
        });
    }
};
