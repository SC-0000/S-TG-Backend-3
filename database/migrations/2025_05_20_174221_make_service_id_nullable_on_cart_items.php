<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeServiceIdNullableOnCartItems extends Migration
{
    public function up()
    {
        Schema::table('cart_items', function (Blueprint $table) {
            // change service_id to be nullable
            $table->unsignedBigInteger('service_id')
                  ->nullable()
                  ->change();
        });
    }

    public function down()
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')
                  ->nullable(false)
                  ->change();
        });
    }
}
