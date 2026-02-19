<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('public_domain')->nullable()->unique()->after('slug');
            $table->string('portal_domain')->nullable()->unique()->after('public_domain');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['public_domain']);
            $table->dropUnique(['portal_domain']);
            $table->dropColumn(['public_domain', 'portal_domain']);
        });
    }
};
