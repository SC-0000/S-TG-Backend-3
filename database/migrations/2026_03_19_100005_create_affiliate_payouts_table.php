<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained('affiliates')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 50)->nullable();
            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['organization_id', 'affiliate_id']);
            $table->index(['affiliate_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_payouts');
    }
};
