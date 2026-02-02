<?php
// database/migrations/2025_05_20_000000_add_product_id_to_cart_items.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProductIdToCartItems extends Migration
{
    public function up()
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('product_id')
                  ->nullable()
                  ->after('service_id')
                  ->constrained()
                  ->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
}
