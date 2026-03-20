<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('schedule_allocations', 'service_ids')) {
            Schema::table('schedule_allocations', function (Blueprint $table) {
                $table->json('service_ids')->nullable()->after('service_id');
            });
        }

        // Migrate existing service_id values into service_ids array
        \Illuminate\Support\Facades\DB::table('schedule_allocations')
            ->whereNotNull('service_id')
            ->whereNull('service_ids')
            ->orderBy('id')
            ->each(function ($alloc) {
                \Illuminate\Support\Facades\DB::table('schedule_allocations')
                    ->where('id', $alloc->id)
                    ->update(['service_ids' => json_encode([$alloc->service_id])]);
            });
    }

    public function down(): void
    {
        Schema::table('schedule_allocations', function (Blueprint $table) {
            $table->dropColumn('service_ids');
        });
    }
};
