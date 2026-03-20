<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('homework_submissions', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')
                    ->nullable()
                    ->after('student_id');
                $table->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->onDelete('set null');
                $table->index('organization_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('homework_submissions', 'organization_id')) {
                $table->dropForeign(['organization_id']);
                $table->dropIndex(['organization_id']);
                $table->dropColumn('organization_id');
            }
        });
    }
};
