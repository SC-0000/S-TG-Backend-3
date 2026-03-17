<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('trigger', 40);           // signup_approved, first_purchase, spend_threshold, every_purchase
            $table->string('commission_type', 20);    // percentage, flat
            $table->decimal('commission_value', 10, 2); // e.g. 10.00 = 10% or £10
            $table->json('conditions')->nullable();   // {"min_spend": 50, "min_total_spend": 200}
            $table->unsignedInteger('priority')->default(0); // higher = evaluated first
            $table->boolean('active')->default(true);
            $table->boolean('one_time')->default(true); // only fire once per referred user
            $table->timestamps();

            $table->index(['organization_id', 'trigger', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
