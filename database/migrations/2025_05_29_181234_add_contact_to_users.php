<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('address_line1')->nullable()->after('role');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('mobile_number')->nullable()->after('address_line2');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['address_line1','address_line2','mobile_number']);
        });
    }
};
