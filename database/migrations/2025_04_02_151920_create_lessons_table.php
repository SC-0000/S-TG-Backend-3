
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();

            /* core info */
            $table->string('title');
            $table->text('description')->nullable();

            /* lesson type & mode */
            $table->enum('lesson_type', ['1:1', 'group'])->default('1:1');
            $table->enum('lesson_mode', ['in_person', 'online'])->default('in_person');

            /* scheduling / location */
            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();
            $table->string('address')->nullable();             // for in-person
            $table->string('meeting_link')->nullable();        // for online

            /* links (nullable so you can attach later) */
            $table->foreignId('instructor_id')->nullable()->constrained('users');
            
            // Define service_id column before adding the foreign key constraint
            $table->foreignId('service_id')->nullable()->constrained('services')->onDelete('cascade'); // which product paid for it?
            
            $table->string('status')->default('scheduled');    // or draft / cancelled / completed

            $table->timestamps();
        });

        /* pivot table child <-> lesson  (many-to-many)  */
        Schema::create('child_lesson', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->boolean('attendance')->nullable();         // null = not taken yet
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_lesson');
        Schema::dropIfExists('lessons');
    }
};
