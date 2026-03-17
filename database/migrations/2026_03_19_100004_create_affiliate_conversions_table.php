<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('tracking_link_id')->nullable()->constrained('tracking_links')->nullOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->nullOnDelete();
            $table->string('application_id', 36)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30)->default('signup');
            $table->decimal('commission_amount', 10, 2)->nullable();
            $table->decimal('commission_rate_snapshot', 5, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('attribution_method', 20)->default('link');
            $table->timestamps();

            $table->index(['organization_id', 'affiliate_id']);
            $table->index(['affiliate_id', 'status']);
            $table->index('application_id');
            $table->index('tracking_link_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_conversions');
    }
};
