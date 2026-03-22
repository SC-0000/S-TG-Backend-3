<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('whatsapp_enabled')->default(false)->after('sms_enabled');
            $table->boolean('whatsapp_opted_in')->default(false)->after('whatsapp_enabled');
            $table->string('phone_number', 20)->nullable()->after('whatsapp_opted_in');
            $table->json('preferred_channels')->nullable()->after('phone_number')
                ->comment('Per notification type, e.g. {"lesson_reminder":["whatsapp","email"]}');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_enabled', 'whatsapp_opted_in', 'phone_number', 'preferred_channels']);
        });
    }
};
