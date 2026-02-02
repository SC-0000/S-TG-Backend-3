<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAlertsTable extends Migration
{
    public function up()
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id('alert_id'); // Unique identifier
            $table->string('title'); // Alert title
            $table->text('message'); // Alert message
            $table->enum('type', ['info', 'warning', 'success', 'error']); // Alert type
            $table->integer('priority'); // Higher number = higher priority
            $table->dateTime('start_time'); // When the alert becomes active
            $table->dateTime('end_time')->nullable(); // Optional expiration time
            $table->json('pages')->nullable(); // List of pages; null for global
            $table->unsignedBigInteger('created_by'); // ID of admin creating the alert
            $table->string('additional_context', 64)->nullable(); // Optional extra context
            $table->timestamps(); // created_at and updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('alerts');
    }
}
