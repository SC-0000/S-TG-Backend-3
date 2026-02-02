<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFeedbacksTable extends Migration
{
    public function up()
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            // Primary key: FeedbackID as an auto-increment integer.
            $table->increments('id');
            // Optional: for logged-in user id (if available)
            $table->string('user_id')->nullable();
            // Required: The name of the user providing feedback.
            $table->text('name');
            // Required: Email address.
            $table->string('user_email');
            // Category: one of the allowed values.
            $table->enum('category', ['Inquiry', 'Complaint', 'Suggestion', 'Support']);
            // Required message text.
            $table->text('message');
            // Attachments stored as JSON.
            $table->json('attachments')->nullable();
            // Status of the feedback.
            $table->enum('status', ['Pending', 'Reviewed', 'Resolved'])->default('Pending');
            // Admin response (if any).
            $table->text('admin_response')->nullable();
            // Submission date: default to current timestamp.
            $table->timestamp('submission_date')->useCurrent();
            // User IP address.
            $table->string('user_ip', 45);
            // created_at and updated_at timestamps.
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('feedbacks');
    }
}
