<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductDiscountsTable extends Migration
{
    public function up()
    {
        Schema::create('product_discounts', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique identifier for the discount.
            $table->unsignedBigInteger('product_id'); // FK → products(id).
            $table->enum('discount_type', ['percentage', 'fixed']); // Type of discount.
            $table->decimal('discount_value', 5, 2); // Discount value.
            $table->dateTime('start_date'); // Discount start date.
            $table->dateTime('end_date'); // Discount end date.
            $table->enum('status', ['active', 'expired']); // Current status of the discount.
            $table->timestamps();

            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_discounts');
    }
}
