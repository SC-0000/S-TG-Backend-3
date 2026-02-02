<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 50)->unique();
            
            // Basic Info
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('order_position')->default(0);
            
            // Status
            $table->enum('status', ['draft', 'review', 'live', 'archived'])->default('draft');
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->json('prerequisites')->nullable(); // [module_id, module_id]
            $table->integer('estimated_minutes')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['course_id', 'order_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
