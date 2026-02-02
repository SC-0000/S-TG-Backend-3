<?php
// database/migrations/2025_07_01_000001_create_journey_categories_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::create('journey_categories', function(Blueprint $t){
            $t->id();
            $t->foreignId('journey_id')
              ->constrained()
              ->cascadeOnDelete();
            $t->string('topic');               // e.g. “Math” (subject)
            $t->string('name');                // e.g. “Algebra”
            $t->text('description')->nullable();
            $t->timestamps();
        });
    }
    public function down() {
        Schema::dropIfExists('journey_categories');
    }
};
