<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homework_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('homework_assignments', 'organization_id')) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete()
                    ->after('created_by');
            }

            if (!Schema::hasColumn('homework_assignments', 'due_date')) {
                return;
            }

            $table->index(['organization_id', 'due_date'], 'homework_assignments_org_due_idx');
        });

        Schema::table('homework_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('homework_submissions', 'organization_id')) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete()
                    ->after('student_id');
            }

            $table->index(['assignment_id'], 'homework_submissions_assignment_idx');
            $table->index(['organization_id'], 'homework_submissions_org_idx');
        });
    }

    public function down(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('homework_submissions', 'organization_id')) {
                $table->dropForeign(['organization_id']);
                $table->dropColumn('organization_id');
            }
            $table->dropIndex('homework_submissions_assignment_idx');
            $table->dropIndex('homework_submissions_org_idx');
        });

        Schema::table('homework_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('homework_assignments', 'organization_id')) {
                $table->dropForeign(['organization_id']);
                $table->dropColumn('organization_id');
            }
            $table->dropIndex('homework_assignments_org_due_idx');
        });
    }
};
