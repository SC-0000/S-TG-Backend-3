<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds onboarding flags for guest/quick-checkout users.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // track whether the user completed onboarding/profile
            $table->boolean('onboarding_complete')->default(false)->after('billing_customer_id');

            // timestamp indicating when this was created as a temporary/guest account
            $table->timestamp('temporary_at')->nullable()->after('onboarding_complete');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['temporary_at', 'onboarding_complete']);
        });
    }
};
