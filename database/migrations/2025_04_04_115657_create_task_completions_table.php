<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaskCompletionsTable extends Migration
{
    public function up()
    {
        Schema::create('task_completions', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique completion record ID.
            // For now, default foreign keys.
            $table->unsignedBigInteger('task_id')->default(1); // FK → tasks(id)
            $table->unsignedBigInteger('user_id')->default(1); // FK → users(id)
            $table->timestamp('completed_at')->useCurrent(); // Completion timestamp.
            $table->text('feedback')->nullable(); // Feedback.
        });
    }

    public function down()
    {
        Schema::dropIfExists('task_completions');
    }
}
