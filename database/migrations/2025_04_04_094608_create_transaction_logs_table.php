<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionLogsTable extends Migration
{
    public function up()
    {
        Schema::create('transaction_logs', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique log ID.
            $table->unsignedBigInteger('transaction_id'); // FK → transactions(id)
            $table->text('log_message'); // Log entry details.
            $table->enum('log_type', ['info', 'warning', 'error']); // Severity.
            $table->timestamp('created_at')->useCurrent(); // Log creation timestamp.
            
            $table->foreign('transaction_id')
                  ->references('id')->on('transactions')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transaction_logs');
    }
}
