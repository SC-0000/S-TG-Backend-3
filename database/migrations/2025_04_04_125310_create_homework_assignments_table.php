<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHomeworkAssignmentsTable extends Migration
{
    public function up()
    {
        Schema::create('homework_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title'); // Title of the assignment
            $table->text('description'); // Detailed instructions
            $table->string('subject'); // Subject or course name
            $table->dateTime('due_date'); // Deadline for submission
            $table->json('attachments')->nullable(); // List of attached files (stored as JSON)
            // For now, default created_by to 1 (later replace with a proper foreign key)
            $table->unsignedBigInteger('created_by')->default(1);
            $table->timestamps(); // includes created_at and updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('homework_assignments');
    }
}
