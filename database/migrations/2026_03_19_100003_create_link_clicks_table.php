<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracking_link_id')->constrained('tracking_links')->cascadeOnDelete();
            $table->string('ip_hash', 64);
            $table->string('user_agent', 500)->nullable();
            $table->string('referer_url', 500)->nullable();
            $table->timestamp('clicked_at');

            $table->index(['tracking_link_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_clicks');
    }
};
