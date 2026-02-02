<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('product_orders', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique identifier for each order.
            $table->unsignedBigInteger('user_id'); // Reference to the user making the order.
            $table->decimal('total_amount', 10, 2); // Total amount for the order.
            $table->enum('payment_status', ['pending', 'completed', 'failed', 'refunded']); // Payment status.
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_orders');
    }
}
