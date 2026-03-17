<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('tracking_code', 32)->nullable()->after('referral_source');
            $table->unsignedBigInteger('affiliate_id')->nullable()->after('tracking_code');

            $table->index('tracking_code');
            $table->index('affiliate_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['tracking_code']);
            $table->dropIndex(['affiliate_id']);
            $table->dropColumn(['tracking_code', 'affiliate_id']);
        });
    }
};
