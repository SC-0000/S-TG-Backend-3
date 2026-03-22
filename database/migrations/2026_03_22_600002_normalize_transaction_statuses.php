<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize all legacy status values to the canonical 'paid' status
        DB::table('transactions')
            ->whereIn('status', ['completed', 'success', 'confirmed'])
            ->update(['status' => 'paid']);
    }

    public function down(): void
    {
        // Not reversible — previous statuses were not consistently applied
    }
};
