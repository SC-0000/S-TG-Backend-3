<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call flow settings are stored in Organization.settings JSON under 'call_flow' key.
 * Structure:
 * {
 *   "call_flow": {
 *     "enabled": true,
 *     "greeting_message": "Thank you for calling. Please hold while we connect you.",
 *     "hold_music_url": null,
 *     "no_answer_message": "Sorry, no one is available. We'll call you back shortly.",
 *     "voicemail_enabled": true,
 *     "ring_timeout_seconds": 30,
 *     "record_calls": true,
 *     "auto_transcribe": true,
 *     "routing": [
 *       { "type": "user", "user_id": 1, "label": "Admin", "priority": 1 },
 *       { "type": "phone", "phone": "+447...", "label": "Mobile", "priority": 2 }
 *     ]
 *   }
 * }
 *
 * No schema changes needed — Organization.settings is already JSON.
 * This migration exists for documentation and to add the voice channel enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add 'voice' to communication_messages channel enum
        // MySQL ALTER ENUM is tricky, so we use a raw statement
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE communication_messages MODIFY COLUMN channel ENUM('email','sms','whatsapp','in_app','push','voice') NOT NULL DEFAULT 'email'"
        );
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE communication_messages MODIFY COLUMN channel ENUM('email','sms','whatsapp','in_app','push') NOT NULL DEFAULT 'email'"
        );
    }
};
