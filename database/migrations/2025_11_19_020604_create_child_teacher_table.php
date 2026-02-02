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
        Schema::create('child_teacher', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->onDelete('cascade');
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_by')->constrained('users');
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamps();
            
            // Prevent duplicate assignments
            $table->unique(['child_id', 'teacher_id', 'organization_id'], 'child_teacher_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('child_teacher');
    }
};
