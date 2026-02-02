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
        Schema::table('admin_tasks', function (Blueprint $table) {
            $table->string('title')->nullable()->after('priority');
            $table->text('description')->nullable()->after('title');
            $table->json('metadata')->nullable()->after('description');
            $table->timestamp('completed_at')->nullable()->after('metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_tasks', function (Blueprint $table) {
            $table->dropColumn(['title', 'description', 'metadata', 'completed_at']);
        });
    }
};
