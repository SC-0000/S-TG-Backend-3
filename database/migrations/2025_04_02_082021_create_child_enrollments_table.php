<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChildEnrollmentsTable extends Migration
{
    public function up()
    {
        Schema::create('child_enrollments', function (Blueprint $table) {
            $table->bigIncrements('id'); // Enrollment record ID.
            $table->unsignedBigInteger('child_id'); // Foreign key to children.
            $table->unsignedBigInteger('course_id'); // Foreign key to courses (assuming courses exist).
            $table->date('start_date'); // Enrollment start date.
            $table->enum('status', ['active', 'pending', 'completed'])->default('pending');
            $table->timestamps();
            $table->foreign('child_id')->references('id')->on('children')->onDelete('cascade');
            // Uncomment if you have a courses table:
            // $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('child_enrollments');
    }
}
