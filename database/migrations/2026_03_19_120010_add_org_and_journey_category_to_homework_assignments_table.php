<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homework_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('homework_assignments', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')
                    ->nullable()
                    ->after('id');
                $table->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->onDelete('set null');
                $table->index('organization_id');
            }

            if (!Schema::hasColumn('homework_assignments', 'journey_category_id')) {
                $table->foreignId('journey_category_id')
                    ->nullable()
                    ->after('subject')
                    ->constrained('journey_categories')
                    ->nullOnDelete();
                $table->index('journey_category_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('homework_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('homework_assignments', 'journey_category_id')) {
                $table->dropForeign(['journey_category_id']);
                $table->dropIndex(['journey_category_id']);
                $table->dropColumn('journey_category_id');
            }

            if (Schema::hasColumn('homework_assignments', 'organization_id')) {
                $table->dropForeign(['organization_id']);
                $table->dropIndex(['organization_id']);
                $table->dropColumn('organization_id');
            }
        });
    }
};
