<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMilestonesTable extends Migration
{
    public function up()
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->bigIncrements('MilestoneID');
            $table->string('Title'); // Not Null
            // Use a date type (or dateTime if you need time details)
            $table->date('Date');   // Not Null
            $table->text('Description'); // Not Null
            $table->string('Image')->nullable(); // Optional file path for image
            $table->integer('DisplayOrder')->nullable(); // Optional order field
            $table->timestamps(); // Creates CreatedAt and UpdatedAt as TIMESTAMP (Not Null)
        });
    }

    public function down()
    {
        Schema::dropIfExists('milestones');
    }
}
