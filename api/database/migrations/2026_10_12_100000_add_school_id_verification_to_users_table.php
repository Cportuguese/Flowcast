<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A patient types their own ID number when they sign up, so until the
     * nurse has compared it with their UDD ID card it is only a claim.
     * Without this, anyone could register a classmate's ID number and the
     * classmate's visits — found at the counter by that number — would be
     * recorded on (and readable from) the impostor's account.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('school_id_verified_at')->nullable()->after('school_id');
            $table->foreignId('school_id_verified_by_id')->nullable()->after('school_id_verified_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id_verified_by_id');
            $table->dropColumn('school_id_verified_at');
        });
    }
};
