<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique identifier for each product.
            $table->string('name'); // Product name.
            $table->text('description')->nullable(); // Detailed product description.
            $table->decimal('price', 10, 2); // Price of the product.
            $table->enum('stock_status', ['in_stock', 'out_of_stock', 'pre_order']); // Availability status.
            $table->string('category'); // Product category.
            $table->string('image_path')->nullable(); // Link to product image.
            $table->unsignedBigInteger('related_lesson_id')->nullable(); // Optional link to a lesson.
            $table->decimal('discount', 5, 2)->nullable(); // Discount applied (if any).
            $table->timestamps();

            $table->foreign('related_lesson_id')
                  ->references('id')
                  ->on('lessons')
                  ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
}
