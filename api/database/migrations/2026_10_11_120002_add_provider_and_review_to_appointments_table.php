<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bookings are now made with a specific professional and start "under
     * review" until the head nurse confirms or declines them.
     *
     * - `pending`  = under review (the new default)
     * - `booked`   = confirmed by the nurse (shown as "Confirmed")
     * - `declined` = turned down by the nurse, with `review_note` saying why
     *
     * Rows from before this change keep a null provider and are treated as
     * bookings with the nurse, which is all they could have been.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('patient_id')->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'booked', 'declined', 'cancelled', 'checked_in', 'no_show', 'completed'])
                ->default('pending')
                ->change();
            $table->foreignId('reviewed_by_id')->nullable()->after('cancel_reason')->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable()->after('reviewed_by_id');
            $table->string('review_note')->nullable()->after('reviewed_at');

            $table->index(['provider_id', 'scheduled_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The old statuses have no "under review" or "declined": for the
        // patient, both meant the booking did not happen.
        DB::table('appointments')->whereIn('status', ['pending', 'declined'])->update(['status' => 'cancelled']);

        // Foreign keys before indexes: MySQL refuses to drop an index that a
        // foreign key still uses (provider_id leads the composite index).
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropForeign(['reviewed_by_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['provider_id', 'scheduled_at']);
            $table->dropIndex(['status']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['provider_id', 'reviewed_by_id', 'reviewed_at', 'review_note']);
            $table->enum('status', ['booked', 'cancelled', 'checked_in', 'no_show', 'completed'])
                ->default('booked')
                ->change();
        });
    }
};
