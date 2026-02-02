<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('product_transactions', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique transaction ID.
            $table->unsignedBigInteger('order_id'); // FK → product_orders(id).
            $table->unsignedBigInteger('user_id'); // FK → users(id).
            $table->enum('payment_method', ['credit_card', 'paypal', 'bank_transfer']); // Payment method used.
            $table->string('transaction_id'); // Unique transaction reference.
            $table->decimal('amount', 10, 2); // Total transaction amount.
            $table->enum('status', ['success', 'failed', 'pending']); // Transaction status.
            $table->timestamps();

            $table->foreign('order_id')
                  ->references('id')
                  ->on('product_orders')
                  ->onDelete('cascade');

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_transactions');
    }
}
