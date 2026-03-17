<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->nullOnDelete();
            $table->string('code', 32)->unique();
            $table->string('label', 255)->nullable();
            $table->string('destination_path', 500)->default('/applications/create');
            $table->string('type', 20)->default('affiliate');
            $table->unsignedBigInteger('click_count')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type']);
            $table->index('affiliate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_links');
    }
};
