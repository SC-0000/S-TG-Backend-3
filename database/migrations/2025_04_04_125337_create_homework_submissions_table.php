<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateHomeworkSubmissionsTable extends Migration
{
    public function up()
    {
        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            // FK to homework_assignments (if desired, uncomment foreign key constraint later)
            $table->unsignedBigInteger('assignment_id');
            // For now, default student_id to 1
            $table->unsignedBigInteger('student_id')->default(1);
            $table->enum('submission_status', ['draft', 'submitted', 'graded']);
            $table->text('content')->nullable();
            $table->json('attachments')->nullable(); // Uploaded files as JSON array
            $table->string('grade')->nullable();
            $table->text('feedback')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('homework_submissions');
    }
}
