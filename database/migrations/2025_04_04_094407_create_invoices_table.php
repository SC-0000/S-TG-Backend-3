<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInvoicesTable extends Migration
{
    public function up()
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id'); // Unique invoice ID.
            $table->unsignedBigInteger('user_id'); // FK → users(id)
            $table->unsignedBigInteger('transaction_id'); // FK → transactions(id)
            $table->string('invoice_number')->unique(); // Unique invoice reference.
            $table->decimal('amount_due', 10, 2); // Amount due.
            $table->dateTime('due_date'); // Payment due date.
            $table->enum('status', ['pending', 'paid', 'overdue', 'canceled']); // Invoice status.
            $table->string('pdf_url')->nullable(); // Downloadable PDF link.
            $table->timestamp('created_at')->useCurrent(); // Invoice creation timestamp.
            // updated_at is automatically managed by Laravel if needed.

            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            $table->foreign('transaction_id')
                  ->references('id')->on('transactions')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoices');
    }
}
