<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique notification ID.
            // For now, we default user_id to 1; later, replace with proper foreign key.
            $table->unsignedBigInteger('user_id')->default(1); // FK → users(id)
            $table->string('title'); // Notification title.
            $table->text('message'); // Notification content.
            $table->enum('type', ['lesson', 'assessment', 'payment', 'task']);
            $table->enum('status', ['unread', 'read'])->default('unread');
            $table->enum('channel', ['email', 'sms', 'in-app', 'push'])->default('in-app');
            $table->timestamp('created_at')->useCurrent(); // Creation timestamp.
        });
    }

    public function down()
    {
        Schema::dropIfExists('app_notifications');
    }
}
