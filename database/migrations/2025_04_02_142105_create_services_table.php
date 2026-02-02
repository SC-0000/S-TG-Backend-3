<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->bigIncrements('id');

            /* Core */
            $table->string('service_name', 255);
            $table->enum('_type', ['lesson', 'assessment', 'bundle']);
            $table->enum('service_level', ['basic', 'full_membership'])->default('basic');
            $table->boolean('availability')->default(true);
            $table->decimal('price', 8, 2)->nullable();

            /* Schedule / capacity */
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('end_datetime')->nullable();
            $table->integer('quantity')->nullable();
            $table->integer('quantity_remaining')->nullable();

            /* Meta */
            $table->string('category')->nullable();
            $table->foreignId('instructor_id')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->json('media')->nullable();
            $table->json('schedule')->nullable();

            /* House-keeping */
            $table->timestamps();
            $table->softDeletes();
        });


    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
