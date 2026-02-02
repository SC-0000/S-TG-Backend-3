<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChildProgressTable extends Migration
{
    public function up()
    {
        Schema::create('child_progress', function (Blueprint $table) {
            $table->bigIncrements('id'); // Primary key.
            $table->unsignedBigInteger('child_id'); // Foreign key to children.
            $table->unsignedBigInteger('lesson_id'); // Foreign key to lessons.
            $table->decimal('progress', 5, 2)->default(0.00); // Percentage of completion.
            $table->text('feedback')->nullable(); // Teacher feedback.
            $table->timestamps();
            $table->foreign('child_id')->references('id')->on('children')->onDelete('cascade');
            // Uncomment if you have a lessons table:
            // $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('child_progress');
    }
}
