<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('children', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('child_name');
            $table->string('emergency_contact_name')->nullable()->after('area');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->text('academic_info')->nullable()->after('other_information');
            $table->text('previous_grades')->nullable()->after('academic_info');
            $table->text('medical_info')->nullable()->after('previous_grades');
            $table->text('additional_info')->nullable()->after('medical_info');
            
        });
    }

    public function down()
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth',
                'emergency_contact_name',
                'emergency_contact_phone',
                'academic_info',
                'previous_grades',
                'medical_info',
                'additional_info',
               
            ]);
        });
    }
};
