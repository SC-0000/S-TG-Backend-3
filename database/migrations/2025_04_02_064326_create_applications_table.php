<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApplicationsTable extends Migration
{
    public function up()
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('application_id')->primary();  // UUID as the primary key
            $table->string('applicant_name', 255);      // Applicant name
            $table->string('email', 255);               // Applicant email
            $table->string('phone_number', 15)->nullable(); // Phone number
            $table->enum('application_status', ['Pending', 'Approved', 'Rejected']); // Application status
            $table->timestamp('submitted_date')->useCurrent(); // Submission date
            $table->enum('application_type', ['Type1', 'Type2', 'Type3']); // Type of application
            $table->text('admin_feedback')->nullable(); // Admin feedback
            $table->uuid('reviewer_id')->nullable();    // Reviewer ID (admin who reviews)
            $table->string('verification_token')->nullable(); // Email verification token
            $table->timestamp('verified_at')->nullable(); // Timestamp for email verification
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('applications');
    }
}
