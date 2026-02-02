<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds idempotency flags to transactions so we don't send duplicate emails.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('email_sent_receipt')->default(false)->after('invoice_id');
            $table->boolean('email_sent_access')->default(false)->after('email_sent_receipt');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['email_sent_access', 'email_sent_receipt']);
        });
    }
};
