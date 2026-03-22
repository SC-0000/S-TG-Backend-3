<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ContentLesson (new_lessons): add 'needs_approval' to enum
        DB::statement("ALTER TABLE new_lessons MODIFY COLUMN status ENUM('draft','review','needs_approval','live','archived') NOT NULL DEFAULT 'draft'");

        // Course: add 'needs_approval' to enum
        DB::statement("ALTER TABLE courses MODIFY COLUMN status ENUM('draft','review','needs_approval','live','archived') NOT NULL DEFAULT 'draft'");

        // Assessment: add 'needs_approval' to enum
        DB::statement("ALTER TABLE assessments MODIFY COLUMN status ENUM('active','inactive','needs_approval','archived') NOT NULL DEFAULT 'active'");

        // Question: already a string column, no schema change needed — just documenting the new value
    }

    public function down(): void
    {
        // Revert ContentLesson
        DB::statement("ALTER TABLE new_lessons MODIFY COLUMN status ENUM('draft','review','live','archived') NOT NULL DEFAULT 'draft'");

        // Revert Course
        DB::statement("ALTER TABLE courses MODIFY COLUMN status ENUM('draft','review','live','archived') NOT NULL DEFAULT 'draft'");

        // Revert Assessment
        DB::statement("ALTER TABLE assessments MODIFY COLUMN status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active'");
    }
};
