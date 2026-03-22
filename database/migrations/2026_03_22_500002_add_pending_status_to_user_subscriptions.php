<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_subscriptions MODIFY COLUMN status ENUM('active','canceled','pending') DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE user_subscriptions MODIFY COLUMN status ENUM('active','canceled') DEFAULT 'active'");
    }
};
