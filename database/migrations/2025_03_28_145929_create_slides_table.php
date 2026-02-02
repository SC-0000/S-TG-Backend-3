<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSlidesTable extends Migration
{
    public function up()
    {
        Schema::create('slides', function (Blueprint $table) {
            // Use a UUID primary key.
            $table->uuid('slide_id')->primary();
            $table->string('title'); // Required title
            $table->json('content'); // Structured content (text, images, multimedia)
            $table->json('template_id')->nullable(); // Optional array of template IDs
            $table->integer('order'); // Display order
            $table->json('tags')->nullable(); // Optional tags (array of strings)
            $table->json('schedule')->nullable(); // Optional schedule data (start/end times)
            $table->string('status'); // e.g., active, draft, archived
            $table->timestamp('last_modified'); // Last modified timestamp
            $table->uuid('created_by')->nullable();
            $table->integer('version'); // Version number for tracking changes
            $table->json('images')->nullable(); // Stored file paths for uploaded images
            $table->timestamps(); // Also provides created_at and updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('slides');
    }
}
