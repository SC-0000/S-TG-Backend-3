<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('item_key');
            $table->string('status')->default('active');
            $table->decimal('price_override', 10, 2)->nullable();
            $table->integer('quantity')->nullable();
            $table->integer('ai_actions_limit')->nullable();
            $table->integer('ai_actions_used')->default(0);
            $table->timestamp('ai_actions_reset_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'category']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_plans');
    }
};
