<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The UDD Health Services record (name parts, birthdate, sex, home
     * address, department and course) for patients, and the profession that
     * makes a staff account a bookable professional.
     *
     * `name` stays: everything that prints a person reads it, and for a
     * patient it is now rebuilt from the name parts on every save.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable()->after('name');
            $table->string('middle_name', 60)->nullable()->after('first_name');
            $table->string('last_name', 60)->nullable()->after('middle_name');
            $table->date('birthdate')->nullable()->after('last_name');
            $table->enum('sex', ['male', 'female'])->nullable()->after('birthdate');
            $table->string('home_address')->nullable()->after('sex');
            $table->string('department', 16)->nullable()->after('patient_type');
            $table->string('course', 16)->nullable()->after('department');
            $table->enum('profession', ['nurse', 'dentist', 'physician'])->nullable()->after('role');

            $table->index('department');
            $table->index(['last_name', 'first_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['department']);
            $table->dropIndex(['last_name', 'first_name']);
            $table->dropColumn([
                'first_name', 'middle_name', 'last_name', 'birthdate', 'sex',
                'home_address', 'department', 'course', 'profession',
            ]);
        });
    }
};
