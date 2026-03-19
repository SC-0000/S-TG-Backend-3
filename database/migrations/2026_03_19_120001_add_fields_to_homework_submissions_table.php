<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('homework_submissions', 'graded_by')) {
                $table->foreignId('graded_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('feedback');
            }

            if (!Schema::hasColumn('homework_submissions', 'attempt')) {
                $table->unsignedInteger('attempt')
                    ->default(1)
                    ->after('graded_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('homework_submissions', 'graded_by')) {
                $table->dropForeign(['graded_by']);
                $table->dropColumn('graded_by');
            }
            if (Schema::hasColumn('homework_submissions', 'attempt')) {
                $table->dropColumn('attempt');
            }
        });
    }
};
