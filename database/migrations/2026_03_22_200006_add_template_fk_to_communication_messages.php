<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_messages', function (Blueprint $table) {
            $table->foreign('template_id')->references('id')->on('communication_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('communication_messages', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
        });
    }
};
