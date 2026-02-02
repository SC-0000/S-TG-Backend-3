<?php
// database/migrations/2025_07_01_000002_add_journey_category_to_lessons_and_assessments.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('lessons', function(Blueprint $t){
            $t->foreignId('journey_category_id')
              ->nullable()
              ->after('service_id')
              ->constrained('journey_categories')
              ->nullOnDelete();
        });
        Schema::table('assessments', function(Blueprint $t){
            $t->foreignId('journey_category_id')
              ->nullable()
              ->after('lesson_id')
              ->constrained('journey_categories')
              ->nullOnDelete();
        });
    }
    public function down() {
        Schema::table('lessons', function(Blueprint $t){
            $t->dropConstrainedForeignId('journey_category_id');
        });
        Schema::table('assessments', function(Blueprint $t){
            $t->dropConstrainedForeignId('journey_category_id');
        });
    }
};
