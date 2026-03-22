<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_health_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedTinyInteger('overall_score')->default(50);
            $table->unsignedTinyInteger('booking_score')->default(50);
            $table->unsignedTinyInteger('payment_score')->default(50);
            $table->unsignedTinyInteger('engagement_score')->default(50);
            $table->unsignedTinyInteger('communication_score')->default(50);
            $table->json('flags')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_booking_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'organization_id']);
            $table->index('organization_id');
            $table->index('overall_score');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_health_scores');
    }
};
