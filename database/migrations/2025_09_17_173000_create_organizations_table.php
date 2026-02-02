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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Organization name
            $table->string('slug')->unique(); // Unique identifier for API/URL
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade'); // Organization owner
            $table->json('settings')->nullable(); // API keys, limits, configurations
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['status']);
            $table->index(['slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
