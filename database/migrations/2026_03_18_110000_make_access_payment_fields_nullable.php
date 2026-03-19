<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access', function (Blueprint $table) {
            $table->string('transaction_id')->nullable()->change();
            $table->string('invoice_id')->nullable()->change();
            $table->timestamp('purchase_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('access', function (Blueprint $table) {
            $table->string('transaction_id')->nullable(false)->change();
            $table->string('invoice_id')->nullable(false)->change();
            $table->timestamp('purchase_date')->nullable(false)->change();
        });
    }
};
