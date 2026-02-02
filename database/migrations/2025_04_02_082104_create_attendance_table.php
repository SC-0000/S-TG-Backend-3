<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAttendanceTable extends Migration
{
    public function up()
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->bigIncrements('id'); // Primary key.
            $table->unsignedBigInteger('child_id'); // Foreign key to children.
            $table->unsignedBigInteger('lesson_id'); // Foreign key to lessons.
            $table->date('date'); // Date of the lesson.
            $table->enum('status', ['present', 'absent', 'late', 'excused']);
            $table->text('notes')->nullable(); // Additional notes.
            $table->timestamps();
            $table->foreign('child_id')->references('id')->on('children')->onDelete('cascade');
            // Uncomment if you have a lessons table:
            // $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('attendance');
    }
}
