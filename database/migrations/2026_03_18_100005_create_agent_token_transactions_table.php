<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_token_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('type', 30); // purchase, consumption, refund, bonus, adjustment
            $table->integer('amount'); // positive = credit, negative = debit
            $table->bigInteger('balance_after');
            $table->string('source_type', 50); // agent_run, manual, stripe, bonus
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->json('metadata')->nullable(); // model, real tokens, cost, etc.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_token_transactions');
    }
};
