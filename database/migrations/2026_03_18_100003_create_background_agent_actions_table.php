<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_agent_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('background_agent_runs')->cascadeOnDelete();
            $table->string('action_type', 50); // check, auto_fix, generate_text, generate_image, send_email, create_record, update_record
            $table->string('target_type', 100)->nullable(); // morph type
            $table->unsignedBigInteger('target_id')->nullable(); // morph id
            $table->text('description');
            $table->json('before_value')->nullable();
            $table->json('after_value')->nullable();
            $table->unsignedInteger('platform_tokens_used')->default(0);
            $table->string('status', 20); // success, failed, skipped
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('run_id');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_agent_actions');
    }
};
