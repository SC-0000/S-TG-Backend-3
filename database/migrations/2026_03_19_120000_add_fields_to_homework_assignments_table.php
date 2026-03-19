<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homework_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('homework_assignments', 'assigned_by')) {
                $table->foreignId('assigned_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('created_by');
            }

            if (!Schema::hasColumn('homework_assignments', 'assigned_by_role')) {
                $table->string('assigned_by_role', 50)
                    ->nullable()
                    ->after('assigned_by');
            }

            if (!Schema::hasColumn('homework_assignments', 'status')) {
                $table->string('status', 30)
                    ->default('draft')
                    ->after('assigned_by_role');
            }

            if (!Schema::hasColumn('homework_assignments', 'visibility')) {
                $table->string('visibility', 30)
                    ->default('both')
                    ->after('status');
            }

            if (!Schema::hasColumn('homework_assignments', 'available_from')) {
                $table->dateTime('available_from')
                    ->nullable()
                    ->after('visibility');
            }

            if (!Schema::hasColumn('homework_assignments', 'grading_mode')) {
                $table->string('grading_mode', 30)
                    ->default('manual')
                    ->after('available_from');
            }

            if (!Schema::hasColumn('homework_assignments', 'settings')) {
                $table->json('settings')
                    ->nullable()
                    ->after('attachments');
            }

            $table->index(['organization_id', 'due_date'], 'homework_assignments_org_due_idx2');
        });
    }

    public function down(): void
    {
        Schema::table('homework_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('homework_assignments', 'assigned_by')) {
                $table->dropForeign(['assigned_by']);
                $table->dropColumn('assigned_by');
            }
            if (Schema::hasColumn('homework_assignments', 'assigned_by_role')) {
                $table->dropColumn('assigned_by_role');
            }
            if (Schema::hasColumn('homework_assignments', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('homework_assignments', 'visibility')) {
                $table->dropColumn('visibility');
            }
            if (Schema::hasColumn('homework_assignments', 'available_from')) {
                $table->dropColumn('available_from');
            }
            if (Schema::hasColumn('homework_assignments', 'grading_mode')) {
                $table->dropColumn('grading_mode');
            }
            if (Schema::hasColumn('homework_assignments', 'settings')) {
                $table->dropColumn('settings');
            }
            $table->dropIndex('homework_assignments_org_due_idx2');
        });
    }
};
