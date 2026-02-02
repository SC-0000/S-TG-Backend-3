<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeCreatedByNullableOnSlidesTable extends Migration
{
    public function up()
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->uuid('created_by')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('slides', function (Blueprint $table) {
            $table->uuid('created_by')->change();
        });
    }
}
