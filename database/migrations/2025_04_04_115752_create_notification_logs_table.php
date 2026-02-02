<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationLogsTable extends Migration
{
    public function up()
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique log ID.
            // Default foreign key for notification_id.
            $table->unsignedBigInteger('notification_id')->default(1); // FK → app_notifications(id)
            $table->string('sent_to'); // Recipient contact.
            $table->enum('sent_via', ['email', 'sms', 'push', 'in-app']);
            $table->enum('status', ['sent', 'failed']);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->useCurrent();
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_logs');
    }
}
