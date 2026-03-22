<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_conditions', function (Blueprint $table) {
            $table->id();
            $table->enum('owner_type', ['platform', 'organization']);
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('title');
            $table->longText('content');
            $table->unsignedInteger('version')->default(1);
            $table->json('applies_to'); // e.g. ["org_admin","teacher"] or ["parent"]
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['owner_type', 'organization_id', 'is_active']);
        });

        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('terms_condition_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->foreign('terms_condition_id')->references('id')->on('terms_conditions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['terms_condition_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
        Schema::dropIfExists('terms_conditions');
    }
};
