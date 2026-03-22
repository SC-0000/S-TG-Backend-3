<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->enum('channel', ['email', 'sms', 'whatsapp', 'multi'])->default('multi');
            $table->string('subject')->nullable();
            $table->text('body_text');
            $table->text('body_html')->nullable();
            $table->json('variables')->nullable()->comment('List of {{variable}} placeholders');
            $table->enum('category', ['reminder', 'marketing', 'transactional', 'followup'])->default('transactional');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'category']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_templates');
    }
};
