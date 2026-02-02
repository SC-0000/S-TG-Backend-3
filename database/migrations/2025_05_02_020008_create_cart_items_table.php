<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            /* FK to carts */
            $table->foreignId('cart_id')
                  ->constrained('carts')
                  ->cascadeOnDelete();

            /* FK to services (or products) */
            $table->foreignId('service_id')
                  ->constrained('services')
                  ->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 8, 2);        // snapshot at time added

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
