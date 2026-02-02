<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()
                  ->constrained()->nullOnDelete();
            $table->string('user_email')->nullable();

            $table->enum('type', ['purchase', 'gift'])->default('purchase');
            $table->enum('status', [
                'pending', 'paid', 'completed', 'shipped', 'refunded'
            ])->default('pending');

            $table->enum('payment_method', [
                'card', 'paypal', 'bank', 'cash', 'manual'
            ])->nullable();

            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('total', 10, 2);

            $table->timestamp('paid_at')->nullable();
            $table->text('comment')->nullable();
            $table->json('meta')->nullable();       // gateway raw payload

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
