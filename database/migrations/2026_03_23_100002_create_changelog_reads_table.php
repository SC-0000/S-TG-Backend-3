<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('changelog_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changelog_entry_id')->constrained('changelog_entries')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['user_id', 'changelog_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changelog_reads');
    }
};
