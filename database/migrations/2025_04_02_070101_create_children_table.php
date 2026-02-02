<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChildrenTable extends Migration
{
    public function up()
    {
        Schema::create('children', function (Blueprint $table) {
            $table->id();  // Primary key for children table
            $table->uuid('application_id');  // Foreign key linking to applications table
            $table->string('child_name');
            $table->integer('age');
            $table->string('school_name');
            $table->string('area');
            $table->string('year_group');
            $table->text('learning_difficulties')->nullable();
            $table->text('focus_targets')->nullable();
            $table->text('other_information')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('application_id')->references('application_id')->on('applications')->onDelete('cascade');
        });
        // Add indexes for better performance
    }

    public function down()
    {
        Schema::dropIfExists('children');
    }
}
