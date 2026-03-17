<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_token_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ai_model', 50); // gpt-5-nano, gpt-5, dall-e-3, etc.
            $table->string('operation_type', 50); // text_generation, image_generation, embedding, pdf_generation
            $table->unsignedInteger('platform_tokens_per_1k_input')->default(0);
            $table->unsignedInteger('platform_tokens_per_1k_output')->default(0);
            $table->unsignedInteger('platform_tokens_flat')->nullable(); // flat rate for image gen, pdf, etc.
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['ai_model', 'operation_type', 'effective_from'], 'atp_model_op_date_unique');
            $table->index(['is_active', 'ai_model', 'operation_type'], 'atp_active_model_op_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_token_pricing');
    }
};
