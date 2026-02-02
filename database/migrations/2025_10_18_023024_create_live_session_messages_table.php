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
        Schema::create('live_session_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_session_id')->constrained('live_lesson_sessions')->onDelete('cascade');
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->text('message');
            $table->enum('type', ['question', 'comment', 'answer'])->default('question');
            $table->boolean('is_answered')->default(false);
            $table->foreignId('answered_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            
            $table->index(['live_session_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_session_messages');
    }
};
