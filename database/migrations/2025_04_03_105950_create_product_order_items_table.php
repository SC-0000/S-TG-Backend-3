<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductOrderItemsTable extends Migration
{
    public function up()
    {
        Schema::create('product_order_items', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique identifier for each order item.
            $table->unsignedBigInteger('order_id'); // FK → product_orders(id).
            $table->unsignedBigInteger('product_id'); // FK → products(id).
            $table->integer('quantity'); // Quantity purchased.
            $table->decimal('price', 10, 2); // Price at the time of purchase.
            $table->timestamps();

            $table->foreign('order_id')
                  ->references('id')
                  ->on('product_orders')
                  ->onDelete('cascade');

            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_order_items');
    }
}
