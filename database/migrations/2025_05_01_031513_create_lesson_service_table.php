<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lesson_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')
                  ->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')
                  ->constrained()->cascadeOnDelete();
        
            /* optional: add 'order' if a bundle needs sequencing */
            // $table->unsignedInteger('display_order')->nullable();
        
            $table->unique(['service_id', 'lesson_id']);   // prevents duplicates
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_service');
    }
};
