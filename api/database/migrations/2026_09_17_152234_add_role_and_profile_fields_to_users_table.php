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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'personnel', 'patient'])->default('patient')->after('password');
            $table->string('school_id')->nullable()->unique()->after('role');
            $table->enum('patient_type', ['student', 'faculty', 'staff'])->nullable()->after('school_id');
            $table->string('contact_no')->nullable()->after('patient_type');
            $table->string('push_token')->nullable()->after('contact_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'school_id', 'patient_type', 'contact_no', 'push_token']);
        });
    }
};
