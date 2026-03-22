<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period_type');
            $table->date('period_date');
            $table->json('metrics');
            $table->decimal('calculated_cost', 10, 2);
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'period_type', 'period_date'], 'org_usage_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_usage_snapshots');
    }
};
