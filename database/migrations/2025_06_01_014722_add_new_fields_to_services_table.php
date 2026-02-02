<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // 1) How many of this service each child is allowed to book
            $table->integer('quantity_allowed_per_child')
                  ->default(1)
                  ->after('service_level'); // move 'after' to wherever it makes sense

            // 2) Which year groups can book this service
            //    Store as JSON array of integers, e.g. [1,2,3,4,5,6]
            $table->json('year_groups_allowed')
                  ->nullable()
                  ->after('quantity_allowed_per_child');

            // 3) Until when the service is visible/available
            $table->date('display_until')
                  ->nullable()
                  ->after('year_groups_allowed');

            // 4) Categories (e.g. "Maths", "Verbal", "Non-Verbal", "English", etc.)
            //    Store as JSON array of strings
            $table->json('categories')
                  ->nullable()
                  ->after('display_until');

            // 5) Whether attendance is automatically tracked for this service
            $table->boolean('auto_attendance')
                  ->default(false)
                  ->after('categories');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('auto_attendance');
            $table->dropColumn('categories');
            $table->dropColumn('display_until');
            $table->dropColumn('year_groups_allowed');
            $table->dropColumn('quantity_allowed_per_child');
        });
    }
};
