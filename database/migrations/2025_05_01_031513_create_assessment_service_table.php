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
        Schema::create('assessment_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')
                  ->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')
                  ->constrained()->cascadeOnDelete();
        
            $table->unique(['service_id', 'assessment_id']);
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_service');
    }
};
