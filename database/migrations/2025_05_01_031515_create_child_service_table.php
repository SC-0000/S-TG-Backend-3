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
        Schema::create('child_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')
                  ->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')
                  ->constrained()->cascadeOnDelete();
        
            /* snapshot the child’s year group at purchase time? */
            // $table->string('year_group')->nullable();
        
            $table->unique(['service_id', 'child_id']);
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('child_service');
    }
};
