<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('transaction_id')->unique()->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('followup_stage')->default(1); // 1=gentle, 2=firm, 3=final, 4=escalated
            $table->timestamp('last_followup_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();
            $table->string('status', 20)->default('active'); // active, resolved, escalated, cancelled
            $table->json('notes')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index(['status', 'next_followup_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_followups');
    }
};
