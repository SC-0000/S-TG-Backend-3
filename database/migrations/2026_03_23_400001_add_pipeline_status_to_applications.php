<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('pipeline_status', 30)->default('new')->after('application_status');
            $table->timestamp('pipeline_status_changed_at')->nullable()->after('pipeline_status');
            $table->index('pipeline_status');
        });

        // Backfill existing records
        DB::table('applications')
            ->where('application_status', 'Approved')
            ->update(['pipeline_status' => 'approved', 'pipeline_status_changed_at' => DB::raw('updated_at')]);

        DB::table('applications')
            ->where('application_status', 'Rejected')
            ->update(['pipeline_status' => 'rejected', 'pipeline_status_changed_at' => DB::raw('updated_at')]);

        DB::table('applications')
            ->where('application_status', 'Pending')
            ->whereNotNull('verified_at')
            ->update(['pipeline_status' => 'verified', 'pipeline_status_changed_at' => DB::raw('verified_at')]);

        DB::table('applications')
            ->where('application_status', 'Pending')
            ->whereNull('verified_at')
            ->update(['pipeline_status' => 'new', 'pipeline_status_changed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['pipeline_status']);
            $table->dropColumn(['pipeline_status', 'pipeline_status_changed_at']);
        });
    }
};
