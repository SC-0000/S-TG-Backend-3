<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->enum('owner_type', ['platform', 'organization'])->default('organization')->after('slug');
            $table->unsignedBigInteger('organization_id')->nullable()->after('owner_type');
            $table->text('description')->nullable()->after('content_filters');
            $table->decimal('price', 10, 2)->nullable()->after('description');
            $table->string('currency', 3)->default('GBP')->after('price');
            $table->enum('billing_interval', ['monthly', 'yearly', 'one_time'])->default('monthly')->after('currency');
            $table->boolean('is_active')->default(true)->after('billing_interval');
            $table->integer('sort_order')->default(0)->after('is_active');
            $table->string('stripe_price_id')->nullable()->after('sort_order');

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['owner_type', 'organization_id', 'is_active']);
        });

        // Mark existing subscriptions as platform-owned (AI subscriptions)
        DB::table('subscriptions')->update(['owner_type' => 'platform']);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['owner_type', 'organization_id', 'is_active']);
            $table->dropForeign(['organization_id']);
            $table->dropColumn([
                'owner_type',
                'organization_id',
                'description',
                'price',
                'currency',
                'billing_interval',
                'is_active',
                'sort_order',
                'stripe_price_id',
            ]);
        });
    }
};
