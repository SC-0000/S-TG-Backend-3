<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telnyx_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('phone_number', 20);
            $table->string('messaging_profile_id')->nullable();
            $table->json('capabilities')->nullable()->comment('e.g. {"sms":true,"whatsapp":true}');
            $table->boolean('is_default')->default(false);
            $table->enum('status', ['active', 'inactive', 'pending'])->default('active');
            $table->timestamps();

            $table->index('organization_id');
            $table->unique(['organization_id', 'phone_number']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telnyx_phone_numbers');
    }
};
