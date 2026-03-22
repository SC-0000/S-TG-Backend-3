<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->enum('channel', ['email', 'sms', 'whatsapp', 'in_app', 'push'])->index();
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->enum('sender_type', ['system', 'agent', 'admin', 'teacher', 'parent', 'external'])->default('system');
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('recipient_address')->nullable();
            $table->string('subject')->nullable();
            $table->text('body_text');
            $table->text('body_html')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('external_id')->nullable();
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed', 'read', 'bounced'])->default('queued');
            $table->unsignedInteger('cost_tokens')->default(0);
            $table->unsignedInteger('cost_currency_amount')->default(0)->comment('Actual provider cost in pence');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'conversation_id']);
            $table->index(['organization_id', 'channel', 'created_at']);
            $table->index('external_id');
            $table->index('recipient_user_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
            // template_id FK deferred — see 2026_03_22_200006 migration
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_messages');
    }
};
