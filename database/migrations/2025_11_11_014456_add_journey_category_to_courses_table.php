<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function(Blueprint $table) {
            $table->foreignId('journey_category_id')
                  ->nullable()
                  ->after('organization_id')
                  ->constrained('journey_categories')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function(Blueprint $table) {
            $table->dropConstrainedForeignId('journey_category_id');
        });
    }
};
