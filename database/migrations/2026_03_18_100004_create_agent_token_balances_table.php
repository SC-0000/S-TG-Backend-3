<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_token_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('lifetime_purchased')->default(0);
            $table->unsignedBigInteger('lifetime_consumed')->default(0);
            $table->unsignedInteger('low_balance_threshold')->default(100);
            $table->boolean('auto_topup_enabled')->default(false);
            $table->unsignedInteger('auto_topup_amount')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_token_balances');
    }
};
