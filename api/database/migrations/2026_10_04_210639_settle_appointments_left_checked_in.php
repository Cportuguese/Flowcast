<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Until the queue started settling appointments, every appointment that went
 * through it stayed "checked in", finished and skipped visits alike. This
 * settles those rows once, the same way the queue now does it live: a done
 * entry completes its appointment and a skipped one makes it a no-show.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['done' => 'completed', 'skipped' => 'no_show'] as $entryStatus => $appointmentStatus) {
            DB::table('appointments')
                ->where('status', 'checked_in')
                ->whereIn('id', DB::table('queue_entries')
                    ->select('appointment_id')
                    ->where('status', $entryStatus)
                    ->whereNotNull('appointment_id'))
                ->update(['status' => $appointmentStatus]);
        }
    }

    /**
     * Not reversible: once the queue settles appointments itself, a row this
     * migration wrote cannot be told apart from one the queue wrote.
     */
    public function down(): void
    {
        //
    }
};
