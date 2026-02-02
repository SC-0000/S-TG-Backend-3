<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdminTasksTable extends Migration
{
    public function up()
    {
        Schema::create('admin_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('task_type'); // e.g., user verification, transaction review
            $table->unsignedBigInteger('assigned_to')->nullable(); // FK to users table (admin user)
            $table->enum('status', ['Pending', 'In Progress', 'Completed']);
            $table->string('related_entity')->nullable(); // Optional reference (user, transaction, etc.)
            $table->enum('priority', ['Low', 'Medium', 'High', 'Critical']);
            $table->timestamps();

            // $table->foreign('assigned_to')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('admin_tasks');
    }
}
