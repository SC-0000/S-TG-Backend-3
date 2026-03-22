<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('item_key');
            $table->string('label');
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2);
            $table->decimal('price_yearly', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('tier')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_pricing');
    }
};
