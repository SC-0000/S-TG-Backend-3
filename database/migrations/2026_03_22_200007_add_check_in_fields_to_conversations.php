<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_check_in_at')->nullable()->after('assigned_to')
                ->comment('Last parent check-in call/meeting date');
            $table->string('last_check_in_type', 20)->nullable()->after('last_check_in_at')
                ->comment('Type of last check-in: call, meeting, message');
            $table->unsignedBigInteger('last_check_in_by')->nullable()->after('last_check_in_type');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['last_check_in_at', 'last_check_in_type', 'last_check_in_by']);
        });
    }
};
