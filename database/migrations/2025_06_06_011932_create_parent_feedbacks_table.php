<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateParentFeedbacksTable extends Migration
{
    public function up()
    {
        Schema::create('parent_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('user_email', 255)->nullable();
            $table->string('category', 100);     // e.g. “General”, “Billing”, etc.
            $table->text('message');
            $table->json('details')->nullable(); // will hold { feature: "...", child_id: X }
            $table->json('attachments')->nullable();
            $table->enum('status', ['New','Reviewed','Closed'])->default('New');
            $table->text('admin_response')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('user_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('parent_feedbacks');
    }
}
