<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedInteger('total_credits');
            $table->unsignedInteger('used_credits')->default(0);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('child_id')
                ->references('id')->on('children')
                ->cascadeOnDelete();

            $table->foreign('service_id')
                ->references('id')->on('services')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')->on('organizations')
                ->nullOnDelete();

            $table->foreign('transaction_id')
                ->references('id')->on('transactions')
                ->nullOnDelete();

            $table->index(['child_id', 'service_id']);
            $table->index(['service_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_credits');
    }
};
