<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationPreferencesTable extends Migration
{
    public function up()
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique preference ID.
            $table->unsignedBigInteger('user_id')->default(1); // FK → users(id)
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_preferences');
    }
}
