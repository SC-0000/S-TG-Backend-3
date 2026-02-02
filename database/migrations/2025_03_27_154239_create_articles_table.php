<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('tag');
            $table->string('name')->unique();
            $table->string('title');
            $table->string('thumbnail')->nullable();
            $table->text('description');
            $table->enum('body_type', ['pdf', 'template']);
            $table->string('pdf')->nullable();
            $table->string('article_template')->nullable();
            $table->string('author');
            $table->string('author_photo')->nullable();
            $table->dateTime('scheduled_publish_date');
            $table->json('titles')->nullable();
            $table->json('bodies')->nullable();
            $table->json('images')->nullable();
            $table->json('key_attributes')->nullable();
            $table->timestamps();
        });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
