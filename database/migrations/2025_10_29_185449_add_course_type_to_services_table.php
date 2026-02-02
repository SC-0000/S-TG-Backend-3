<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE services MODIFY _type ENUM('lesson', 'assessment', 'bundle', 'course')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE services MODIFY _type ENUM('lesson', 'assessment', 'bundle')");
    }
};
