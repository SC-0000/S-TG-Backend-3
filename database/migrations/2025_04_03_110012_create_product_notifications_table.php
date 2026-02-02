<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('product_notifications', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique notification ID.
            $table->unsignedBigInteger('user_id'); // Reference to the recipient.
            $table->text('message'); // Notification content.
            $table->enum('type', ['order_update', 'discount_alert']); // Type of notification.
            $table->boolean('read_status')->default(false); // Read/unread status.
            $table->timestamp('created_at')->useCurrent(); // Timestamp when notification was sent.

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_notifications');
    }
}
