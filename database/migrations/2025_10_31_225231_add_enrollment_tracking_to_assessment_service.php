<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('assessment_service', function (Blueprint $table) {
            $table->unsignedInteger('enrollment_limit')->nullable()->after('assessment_id')->comment('Max students for this assessment in this service');
            $table->unsignedInteger('current_enrollments')->default(0)->after('enrollment_limit')->comment('Current number of enrolled students');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessment_service', function (Blueprint $table) {
            $table->dropColumn(['enrollment_limit', 'current_enrollments']);
        });
    }
};
