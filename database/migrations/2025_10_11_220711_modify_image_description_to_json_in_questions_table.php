<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, migrate existing data to JSON format
        $questions = DB::table('questions')
            ->whereNotNull('image_description')
            ->where('image_description', '!=', '')
            ->get();

        foreach ($questions as $question) {
            // Convert old string descriptions to new JSON format
            $imageDescriptions = [];
            
            if ($question->question_type === 'mcq') {
                $imageDescriptions['question_image'] = $question->image_description;
            } elseif ($question->question_type === 'image_grid_mcq') {
                // If there's a generic description, use it for the first option
                $imageDescriptions['option_a'] = $question->image_description;
            }
            
            DB::table('questions')
                ->where('id', $question->id)
                ->update(['image_description' => json_encode($imageDescriptions)]);
        }

        // Now modify the column
        Schema::table('questions', function (Blueprint $table) {
            $table->json('image_description')->nullable()->change();
        });

        // Rename the column
        Schema::table('questions', function (Blueprint $table) {
            $table->renameColumn('image_description', 'image_descriptions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename back
        Schema::table('questions', function (Blueprint $table) {
            $table->renameColumn('image_descriptions', 'image_description');
        });

        // Convert JSON back to text
        Schema::table('questions', function (Blueprint $table) {
            $table->text('image_description')->nullable()->change();
        });

        // Migrate data back to single string (take first value from JSON)
        $questions = DB::table('questions')
            ->whereNotNull('image_description')
            ->get();

        foreach ($questions as $question) {
            $descriptions = json_decode($question->image_description, true);
            if (is_array($descriptions) && !empty($descriptions)) {
                $firstDescription = reset($descriptions);
                DB::table('questions')
                    ->where('id', $question->id)
                    ->update(['image_description' => $firstDescription]);
            }
        }
    }
};
