<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'is_global')) {
                $table->boolean('is_global')->default(false)->after('organization_id');
                $table->index('is_global');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'is_global')) {
                $table->dropIndex(['is_global']);
                $table->dropColumn('is_global');
            }
        });
    }
};
