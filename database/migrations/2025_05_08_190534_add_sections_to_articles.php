<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // new flexible storage
            $table->json('sections')->nullable()->after('description');

            // make the old arrays optional so legacy rows stay valid
            $table->json('titles')->nullable()->change();
            $table->json('bodies')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('sections');
            // you can’t restore NOT NULL safely, so leave titles/bodies nullable
        });
    }
};
