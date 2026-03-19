<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Org scope (nullable so super-admin global actions are captured too)
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();

            // Actor — denormalised so logs survive user deletion
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 120)->nullable();
            $table->string('user_role', 60)->nullable();

            // Action
            $table->string('action', 20); // created | updated | deleted

            // Resource — denormalised name for display without joins
            $table->string('resource_type', 60);
            $table->unsignedBigInteger('resource_id');
            $table->string('resource_name', 255)->nullable();

            // Compact JSON diff: { "field": { "from": x, "to": y } }
            $table->json('changes')->nullable();

            // Request context
            $table->string('ip_address', 45)->nullable();

            // Immutable — no updated_at
            $table->timestamp('created_at')->useCurrent();

            // ── Indexes (query patterns: list by org+time, resource lookup, user activity)
            $table->index(['organization_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at'); // for cleanup / global super-admin view
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
