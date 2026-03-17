<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->foreignId('commission_rule_id')->nullable()->after('attribution_method')
                  ->constrained('commission_rules')->nullOnDelete();
            $table->string('trigger_event', 40)->nullable()->after('commission_rule_id');
            $table->foreignId('transaction_id')->nullable()->after('trigger_event')
                  ->constrained('transactions')->nullOnDelete();

            $table->index('commission_rule_id');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->dropForeign(['commission_rule_id']);
            $table->dropForeign(['transaction_id']);
            $table->dropColumn(['commission_rule_id', 'trigger_event', 'transaction_id']);
        });
    }
};
