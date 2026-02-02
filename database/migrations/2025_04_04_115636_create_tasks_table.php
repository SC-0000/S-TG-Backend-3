<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTasksTable extends Migration
{
    public function up()
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique task ID.
            // For now, default assigned_to and created_by to 1.
            $table->unsignedBigInteger('assigned_to')->default(1); // FK → users(id)
            $table->unsignedBigInteger('created_by')->default(1);    // FK → users(id)
            $table->string('title'); // Task title.
            $table->text('description')->nullable(); // Detailed instructions.
            $table->dateTime('due_date'); // Due date.
            $table->enum('priority', ['low', 'medium', 'high']);
            $table->enum('status', ['pending', 'completed', 'overdue'])->default('pending');
            $table->timestamp('created_at')->useCurrent(); // Task creation timestamp.
        });
    }

    public function down()
    {
        Schema::dropIfExists('tasks');
    }
}
