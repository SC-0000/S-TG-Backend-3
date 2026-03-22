<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->string('event_type', 80)->nullable()->after('webhook_delivery_id');
            $table->json('payload')->nullable()->after('event_type');
            $table->string('source_ip', 45)->nullable()->after('payload');

            $table->index(['event_type', 'created_at']);
            $table->index(['log_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->dropIndex(['event_type', 'created_at']);
            $table->dropIndex(['log_type', 'created_at']);
            $table->dropColumn(['event_type', 'payload', 'source_ip']);
        });
    }
};
