<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('access', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('child_id');
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->unsignedBigInteger('assessment_id')->nullable();
            $table->json('lesson_ids')->nullable();
            $table->json('assessment_ids')->nullable();
            $table->string('transaction_id');
            $table->string('invoice_id');
            $table->timestamp('purchase_date');
            $table->date('due_date')->nullable();
            $table->boolean('access');
            $table->enum('payment_status', ['paid', 'refunded', 'disputed', 'failed']);
            $table->string('refund_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('child_id')->references('id')->on('children')->onDelete('cascade');
            $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('set null');
            $table->foreign('assessment_id')->references('id')->on('assessments')->onDelete('set null');
            // No foreign key for invoice_id, as there is no invoices table
        });
    }

    public function down()
    {
        Schema::dropIfExists('access');
    }
};
