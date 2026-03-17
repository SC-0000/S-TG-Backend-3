<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('phone', 50)->nullable();
            $table->string('magic_token', 64)->unique()->nullable();
            $table->timestamp('magic_token_expires_at')->nullable();
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('meta')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'email']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
