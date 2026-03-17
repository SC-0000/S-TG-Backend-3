<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_agent_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('agent_type', 50);
            $table->boolean('is_enabled')->default(true);
            $table->string('schedule_override')->nullable(); // cron expression
            $table->json('settings')->nullable(); // agent-specific config
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'agent_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_agent_configs');
    }
};
