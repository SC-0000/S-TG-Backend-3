<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            // Classification
            $table->string('type', 30)->index(); // pdf, video, image, document, audio, spreadsheet, presentation, archive, other
            $table->string('title');
            $table->text('description')->nullable();

            // Storage
            $table->string('storage_disk', 30)->default('public'); // public, s3, local
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Access control
            $table->string('visibility', 30)->default('org'); // private, org, teachers_only, parents_only, students, public
            $table->string('status', 30)->default('ready')->index(); // processing, ready, archived, failed

            // Media-specific
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable(); // for video/audio
            $table->text('transcript_text')->nullable();

            // Source tracking
            $table->string('source_type', 30)->default('upload'); // upload, external_link, imported_drive
            $table->text('source_url')->nullable();

            // Metadata
            $table->json('metadata')->nullable(); // width, height, pages, bitrate, etc.
            $table->json('tags')->nullable(); // ["fractions", "ks2", "help_video"]

            // Soft archive
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->index(['organization_id', 'type']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
