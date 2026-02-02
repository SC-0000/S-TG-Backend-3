<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id'); // Primary key.
            $table->unsignedBigInteger('child_id'); // Foreign key to children.
            $table->text('message'); // Notification content.
            $table->enum('type', ['lesson_update', 'invoice_due', 'general']);
            $table->boolean('read_status')->default(false); // Read/unread status.
            $table->timestamps();
            $table->foreign('child_id')->references('id')->on('children')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('notifications');
    }
}
