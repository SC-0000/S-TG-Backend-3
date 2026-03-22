<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->string('webhook_delivery_id')->nullable()->unique()->after('log_type');
            $table->unsignedBigInteger('transaction_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->dropUnique(['webhook_delivery_id']);
            $table->dropColumn('webhook_delivery_id');
        });
    }
};
