<?php
// database/migrations/2025_07_28_000000_create_teachers_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTeachersTable extends Migration
{
    public function up()
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
            $table->string('name', 255);
            $table->string('title', 255);
            $table->string('role', 255)->nullable();
            $table->text('bio');
            $table->string('category', 100)->nullable();
            $table->json('metadata')->nullable();    // phone, email, address
            $table->json('specialties')->nullable(); // ["Mathematics",...]
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('teachers');
    }
}
