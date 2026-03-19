<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('homework_id')
                ->constrained('homework_assignments')
                ->cascadeOnDelete();
            $table->string('type', 50);
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['homework_id', 'type'], 'homework_items_homework_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_items');
    }
};
