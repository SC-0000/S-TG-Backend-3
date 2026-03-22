<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_templates', function (Blueprint $table) {
            $table->string('system_key', 60)->nullable()->after('name');

            // Allow platform-level defaults (no org) for system templates
            $table->unsignedBigInteger('organization_id')->nullable()->change();

            $table->index('system_key');
        });
    }

    public function down(): void
    {
        Schema::table('communication_templates', function (Blueprint $table) {
            $table->dropIndex(['system_key']);
            $table->dropColumn('system_key');

            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });
    }
};
