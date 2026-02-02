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
        Schema::table('new_lessons', function (Blueprint $table) {
            $table->foreignId('journey_category_id')
                  ->nullable()
                  ->after('organization_id')
                  ->constrained('journey_categories')
                  ->cascadeOnDelete();
            
            $table->index('journey_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('new_lessons', function (Blueprint $table) {
            $table->dropForeign(['journey_category_id']);
            $table->dropIndex(['journey_category_id']);
            $table->dropColumn('journey_category_id');
        });
    }
};
