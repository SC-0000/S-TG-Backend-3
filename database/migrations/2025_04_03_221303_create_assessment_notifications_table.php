<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssessmentNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('assessment_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assessment_id'); // FK to assessments(id)
            $table->unsignedBigInteger('user_id'); // FK to users(id)
            $table->text('message'); // Notification content.
            $table->enum('type', ['reminder', 'result', 'deadline']);
            $table->boolean('read_status')->default(false); // Read/unread status.
            $table->timestamp('created_at')->useCurrent(); // Timestamp when notification was sent.

            $table->foreign('assessment_id')->references('id')->on('assessments')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('assessment_notifications');
    }
}
