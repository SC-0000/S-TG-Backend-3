<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1) Add `role` column to users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('basic')->after('password')->index();
            }
        });

        // 2) Create teacher_profiles table
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->string('display_name')->nullable();
            $table->text('bio')->nullable();
            $table->json('qualifications')->nullable();
            $table->json('metadata')->nullable(); // free-form metadata column as requested
            $table->integer('max_hours_per_day')->nullable()->default(8);
            $table->integer('max_hours_per_week')->nullable()->default(40);
            $table->timestamps();

            $table->index('user_id');
        });

        // 3) Create teacher_availabilities table
        Schema::create('teacher_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained('teacher_profiles')->onDelete('cascade');
            $table->tinyInteger('day_of_week')->comment('0 = Sunday, 6 = Saturday')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_recurring')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['teacher_profile_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop availability first because of FK
        Schema::dropIfExists('teacher_availabilities');

        // Drop teacher profiles
        Schema::dropIfExists('teacher_profiles');

        // Remove role column from users if exists
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role')) {
                $table->dropIndex(['role']);
                $table->dropColumn('role');
            }
        });
    }
};
