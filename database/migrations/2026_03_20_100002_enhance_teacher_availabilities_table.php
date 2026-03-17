<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_availabilities', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('is_recurring');
            $table->date('effective_until')->nullable()->after('effective_from');
            $table->unsignedInteger('slot_duration_minutes')->default(60)->after('effective_until');
            $table->unsignedInteger('buffer_minutes')->default(0)->after('slot_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_availabilities', function (Blueprint $table) {
            $table->dropColumn([
                'effective_from',
                'effective_until',
                'slot_duration_minutes',
                'buffer_minutes',
            ]);
        });
    }
};
