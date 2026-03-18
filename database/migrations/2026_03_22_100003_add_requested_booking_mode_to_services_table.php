<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE services MODIFY COLUMN booking_mode
             ENUM('fixed_schedule','flexible_booking','self_paced','none','requested') NULL"
        );
    }

    public function down(): void
    {
        // Demote any 'requested' rows to 'none' before removing the enum value
        DB::statement("UPDATE services SET booking_mode = 'none' WHERE booking_mode = 'requested'");

        DB::statement(
            "ALTER TABLE services MODIFY COLUMN booking_mode
             ENUM('fixed_schedule','flexible_booking','self_paced','none') NULL"
        );
    }
};
