<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Free plans (user_seat, platform) should not have payment_status
        DB::table('organization_plans')
            ->whereIn('category', ['user_seat', 'platform'])
            ->update(['payment_status' => null]);

        // Plans that were created before billing integration and are active should be treated as paid
        DB::table('organization_plans')
            ->where('status', 'active')
            ->where('payment_status', 'pending')
            ->whereNull('billing_invoice_id')
            ->update(['payment_status' => null]);
    }

    public function down(): void
    {
        // No rollback needed
    }
};
