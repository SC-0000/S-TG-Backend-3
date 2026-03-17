<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('agent_type', 50)->index();
            $table->string('trigger_type', 20); // scheduled, event, manual
            $table->string('trigger_reference')->nullable();
            $table->string('status', 20)->default('pending'); // pending, running, completed, failed, skipped
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('items_processed')->default(0);
            $table->unsignedInteger('items_affected')->default(0);
            $table->text('error_message')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedInteger('platform_tokens_used')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'agent_type']);
            $table->index(['agent_type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_agent_runs');
    }
};
