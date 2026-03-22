<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_invoices', function (Blueprint $table) {
            $table->renameColumn('stripe_invoice_id', 'billing_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_invoices', function (Blueprint $table) {
            $table->renameColumn('billing_invoice_id', 'stripe_invoice_id');
        });
    }
};
