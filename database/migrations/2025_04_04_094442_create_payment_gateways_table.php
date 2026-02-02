<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentGatewaysTable extends Migration
{
    public function up()
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique gateway ID.
            $table->string('name'); // Gateway name.
            $table->string('api_key'); // API key (store securely, consider encryption).
            $table->enum('status', ['active', 'inactive']); // Gateway availability.
            $table->timestamp('created_at')->useCurrent(); // Setup timestamp.
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_gateways');
    }
}
