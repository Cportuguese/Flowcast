<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two changes from the alpha-test review:
     *
     * - The record says what was DONE for the patient, not a diagnosis: the
     *   clinic's nurse does not diagnose. `diagnosis` becomes `intervention`
     *   and the overlapping `treatment` becomes free-text `remarks`.
     * - The dentist and the school physician see booked patients at their
     *   booked time, without a queue number, so a visit now belongs to a
     *   queue entry (walk-ins and the nurse's bookings) OR an appointment.
     */
    public function up(): void
    {
        Schema::table('visit_records', function (Blueprint $table) {
            $table->renameColumn('diagnosis', 'intervention');
            $table->renameColumn('treatment', 'remarks');
        });

        Schema::table('visit_records', function (Blueprint $table) {
            $table->unsignedBigInteger('queue_entry_id')->nullable()->change();
            $table->foreignId('appointment_id')->nullable()->unique()->after('queue_entry_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * `queue_entry_id` stays nullable: the dentist's and physician's visits
     * have no queue entry, and making it required again would mean deleting
     * those health records to roll back.
     */
    public function down(): void
    {
        // Foreign key, then the unique index it leans on, then the column:
        // MySQL refuses to drop an index a foreign key still needs, and
        // SQLite refuses to drop a column an index still names.
        Schema::table('visit_records', function (Blueprint $table) {
            $table->dropForeign(['appointment_id']);
        });

        Schema::table('visit_records', function (Blueprint $table) {
            $table->dropUnique(['appointment_id']);
        });

        Schema::table('visit_records', function (Blueprint $table) {
            $table->dropColumn('appointment_id');
        });

        Schema::table('visit_records', function (Blueprint $table) {
            $table->renameColumn('intervention', 'diagnosis');
            $table->renameColumn('remarks', 'treatment');
        });
    }
};
