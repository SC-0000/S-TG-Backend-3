<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTestimonialsTable extends Migration
{
    public function up()
    {
        Schema::create('testimonials', function (Blueprint $table) {
            // Primary key for testimonial entry.
            $table->bigIncrements('TestimonialID');
            
            // User information
            $table->string('UserName', 255);   // Required
            $table->string('UserEmail', 255);  // Required

            // Testimonial content
            $table->text('Message');           // Not Null

            // Optional rating: using unsigned tinyInteger (could be 1-5) or ENUM if preferred.
            $table->unsignedTinyInteger('Rating')->nullable();

            // Attachments stored as JSON; you may later cast it to array.
            $table->string('Attachments')->nullable();

            // Moderation status: Not Null; possible values: Pending, Approved, Declined.
            $table->enum('Status', ['Pending', 'Approved', 'Declined']);

            // Optional admin comment.
            $table->text('AdminComment')->nullable();

            // Submission date: not null, defaulting to the current timestamp.
            $table->timestamp('SubmissionDate')->useCurrent();

            // User IP address (max 45 characters to cover IPv6).
            $table->string('UserIP', 45);

            // Optional display order for published testimonials.
            $table->integer('DisplayOrder')->nullable();

            // If you wish to use updated_at timestamps as well.
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('testimonials');
    }
}
