<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('setup_token')->nullable()->after('remember_token');
            $table->timestamp('setup_token_expires_at')->nullable()->after('setup_token');
            $table->index('setup_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['setup_token']);
            $table->dropColumn(['setup_token', 'setup_token_expires_at']);
        });
    }
};
