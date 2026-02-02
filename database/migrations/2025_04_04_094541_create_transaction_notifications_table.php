<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('transaction_notifications', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique notification ID.
            $table->unsignedBigInteger('user_id'); // Recipient.
            $table->text('message'); // Notification content.
            $table->enum('type', ['payment_success', 'invoice_due', 'refund_update']); // Notification type.
            $table->boolean('read_status')->default(false); // Read/unread.
            $table->timestamp('created_at')->useCurrent(); // Timestamp.
            
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transaction_notifications');
    }
}
