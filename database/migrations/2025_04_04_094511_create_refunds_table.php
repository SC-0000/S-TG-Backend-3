<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRefundsTable extends Migration
{
    public function up()
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique refund ID.
            $table->unsignedBigInteger('transaction_id'); // FK → transactions(id)
            $table->unsignedBigInteger('user_id'); // FK → users(id)
            $table->decimal('amount_refunded', 10, 2); // Amount refunded.
            $table->text('refund_reason')->nullable(); // Reason for refund.
            $table->enum('status', ['pending', 'completed', 'rejected']); // Refund status.
            $table->timestamp('created_at')->useCurrent(); // Request timestamp.

            $table->foreign('transaction_id')
                  ->references('id')->on('transactions')
                  ->onDelete('cascade');

            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('refunds');
    }
}
