<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('completed_at');
            $table->string('source', 20)->default('manual')->after('due_at');
            $table->string('source_model_type')->nullable()->after('source');
            $table->unsignedBigInteger('source_model_id')->nullable()->after('source_model_type');
            $table->string('auto_resolve_event', 100)->nullable()->after('source_model_id');
            $table->timestamp('assigned_at')->nullable()->after('auto_resolve_event');
            $table->timestamp('snoozed_until')->nullable()->after('assigned_at');
            $table->string('category', 50)->nullable()->after('snoozed_until');
            $table->string('action_url')->nullable()->after('category');

            $table->index(['assigned_to', 'status', 'due_at'], 'admin_tasks_assignee_status_due_idx');
            $table->index(['source_model_type', 'source_model_id'], 'admin_tasks_source_model_idx');
            $table->index(['task_type', 'status'], 'admin_tasks_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            $table->dropIndex('admin_tasks_assignee_status_due_idx');
            $table->dropIndex('admin_tasks_source_model_idx');
            $table->dropIndex('admin_tasks_type_status_idx');

            $table->dropColumn([
                'due_at', 'source', 'source_model_type', 'source_model_id',
                'auto_resolve_event', 'assigned_at', 'snoozed_until',
                'category', 'action_url',
            ]);
        });
    }
};
