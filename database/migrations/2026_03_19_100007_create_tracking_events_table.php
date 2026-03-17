<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracking_link_id')->constrained('tracking_links')->cascadeOnDelete();
            $table->string('session_hash', 64);            // SHA-256 of IP+UA for anonymous session
            $table->string('event', 40);                    // click, page_view, form_start, form_submit, verified, approved
            $table->string('page_path', 500)->nullable();   // which page triggered the event
            $table->json('meta')->nullable();                // extra data (referrer, UTM, etc.)
            $table->timestamp('occurred_at');

            $table->index(['tracking_link_id', 'event']);
            $table->index(['tracking_link_id', 'session_hash']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
