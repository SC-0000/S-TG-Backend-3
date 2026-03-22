<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add application_id if it doesn't exist
        if (!Schema::hasColumn('terms_acceptances', 'application_id')) {
            Schema::table('terms_acceptances', function (Blueprint $table) {
                $table->string('application_id', 36)->nullable()->after('user_id');
            });
        }

        // Make user_id nullable if not already
        Schema::table('terms_acceptances', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // Must drop ALL foreign keys that rely on columns in the unique index before dropping it.
        // The unique index on (terms_condition_id, user_id) backs both the user_id FK and terms_condition_id FK.
        $this->dropForeignIfExists('terms_acceptances', 'terms_acceptances_user_id_foreign');
        $this->dropForeignIfExists('terms_acceptances', 'terms_acceptances_terms_condition_id_foreign');

        // Now we can safely drop the old unique index
        $this->dropIndexIfExists('terms_acceptances', 'terms_acceptances_terms_condition_id_user_id_unique');

        // Recreate both FKs and add new constraints
        Schema::table('terms_acceptances', function (Blueprint $table) {
            $table->foreign('terms_condition_id')->references('id')->on('terms_conditions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['terms_condition_id', 'user_id', 'application_id'], 'terms_acceptances_unique');
        });

        // Add application_id index if it doesn't exist
        $this->addIndexIfNotExists('terms_acceptances', 'application_id', 'terms_acceptances_application_id_index');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('terms_acceptances', 'terms_acceptances_unique');
        $this->dropIndexIfExists('terms_acceptances', 'terms_acceptances_application_id_index');

        if (Schema::hasColumn('terms_acceptances', 'application_id')) {
            Schema::table('terms_acceptances', function (Blueprint $table) {
                $table->dropColumn('application_id');
            });
        }

        $this->dropForeignIfExists('terms_acceptances', 'terms_acceptances_user_id_foreign');
        $this->dropForeignIfExists('terms_acceptances', 'terms_acceptances_terms_condition_id_foreign');

        Schema::table('terms_acceptances', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('terms_condition_id')->references('id')->on('terms_conditions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['terms_condition_id', 'user_id']);
        });
    }

    private function dropForeignIfExists(string $table, string $foreignKey): void
    {
        $fks = collect(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table]
        ));
        if ($fks->contains('CONSTRAINT_NAME', $foreignKey)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($foreignKey));
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $indexes = collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]));
        if ($indexes->isNotEmpty()) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
        }
    }

    private function addIndexIfNotExists(string $table, string $column, string $indexName): void
    {
        $indexes = collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]));
        if ($indexes->isEmpty()) {
            Schema::table($table, fn (Blueprint $t) => $t->index($column, $indexName));
        }
    }
};
