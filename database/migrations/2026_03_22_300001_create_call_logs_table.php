<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('telnyx_call_control_id')->nullable()->index();
            $table->string('telnyx_call_leg_id')->nullable();
            $table->string('from_number', 20);
            $table->string('to_number', 20);
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->unsignedBigInteger('initiated_by')->nullable()->comment('User who started the call');
            $table->unsignedBigInteger('recipient_user_id')->nullable()->comment('Parent user being called');
            $table->enum('status', [
                'initiating', 'ringing', 'answered', 'bridging',
                'recording', 'completed', 'failed', 'missed',
                'voicemail', 'busy', 'no_answer',
            ])->default('initiating');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('recording_url')->nullable();
            $table->enum('recording_status', ['none', 'recording', 'processing', 'ready', 'failed'])->default('none');
            $table->text('transcription')->nullable();
            $table->text('ai_summary')->nullable();
            $table->unsignedInteger('cost_tokens')->default(0);
            $table->unsignedInteger('cost_currency_amount')->default(0)->comment('Provider cost in pence');
            $table->json('metadata')->nullable()->comment('Call flow, routing, DTMF, etc.');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['conversation_id']);
            $table->index(['recipient_user_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
            $table->foreign('initiated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('recipient_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
