<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained()      // ↳ references users.id & adds FK index
                  ->nullOnDelete();    // if you ever delete the user
        });
    }

    public function down()
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropConstrainedForeignId('user_id'));
    }
};
