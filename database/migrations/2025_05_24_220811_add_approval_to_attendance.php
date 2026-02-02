<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('attendance', function (Blueprint $table) {
        $table->enum('status', ['present','absent','late','excused','pending'])
              ->default('pending')
              ->change();               // keep your existing values

        $table->boolean('approved')->default(false);
        $table->unsignedBigInteger('approved_by')->nullable();
        $table->timestamp('approved_at')->nullable();

        $table->foreign('approved_by')
              ->references('id')->on('users')
              ->nullOnDelete();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved', 'approved_by', 'approved_at']);
            $table->enum('status', ['present','absent','late','excused'])
                  ->default('absent')
                  ->change(); // revert to previous status values
        });
    }
};
