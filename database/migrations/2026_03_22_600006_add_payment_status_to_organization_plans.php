<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_plans', function (Blueprint $table) {
            $table->string('payment_status')->nullable()->after('status');
            $table->string('billing_invoice_id')->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('organization_plans', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'billing_invoice_id']);
        });
    }
};
