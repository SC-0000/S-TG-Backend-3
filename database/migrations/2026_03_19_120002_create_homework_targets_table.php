<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_targets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('homework_id')
                ->constrained('homework_assignments')
                ->cascadeOnDelete();
            $table->foreignId('child_id')
                ->constrained('children')
                ->cascadeOnDelete();
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['homework_id', 'child_id'], 'homework_targets_unique');
            $table->index(['child_id', 'assigned_at'], 'homework_targets_child_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_targets');
    }
};
