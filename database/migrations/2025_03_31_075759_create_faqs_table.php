<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFaqsTable extends Migration
{
    public function up()
    {
        Schema::create('faqs', function (Blueprint $table) {
            // Use a UUID as the primary key.
            $table->uuid('id')->primary();
            $table->text('question'); // FAQ question
            $table->text('answer');   // FAQ answer
            $table->string('category')->nullable(); // Optional category
            // We'll store tags as a JSON array.
            $table->json('tags')->nullable(); 
            $table->boolean('published'); // FAQ visibility
            $table->uuid('author_id');    // Admin who created/updated FAQ
            $table->string('image')->nullable(); // Optional image (stored as file path or URL)
            // created_at and updated_at are added automatically by timestamps().
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('faqs');
    }
}