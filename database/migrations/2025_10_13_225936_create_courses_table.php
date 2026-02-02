<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 50)->unique();
            
            // Basic Info
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('thumbnail')->nullable();
            
            // Status & Versioning
            $table->enum('status', ['draft', 'review', 'live', 'archived'])->default('draft');
            $table->integer('version')->default(1);
            $table->json('change_log')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            
            // Authorship
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            
            // Sharing
            $table->boolean('is_global')->default(false);
            $table->foreignId('source_organization_id')->nullable()->constrained('organizations');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['organization_id', 'status']);
            $table->index(['is_global', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
