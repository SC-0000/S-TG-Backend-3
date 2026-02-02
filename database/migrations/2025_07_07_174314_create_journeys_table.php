<?php
// database/migrations/2025_07_01_000000_create_journeys_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('journeys', function(Blueprint $t){
            $t->id();
            $t->string('title');               // e.g. “11Plus”
            $t->text('description')->nullable();
            $t->date('exam_end_date')->nullable();
            $t->timestamps();
        });
    }
    public function down() {
        Schema::dropIfExists('journeys');
    }
};
