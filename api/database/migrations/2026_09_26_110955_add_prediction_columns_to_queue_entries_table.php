<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Recording the wait prediction made at arrival turns every real visit
     * into an online evaluation point: predicted vs actual (joined_at to
     * called_at) stays measurable on live data, not just on a training
     * holdout.
     */
    public function up(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('predicted_wait_minutes')->nullable()->after('completed_at');
            $table->unsignedSmallInteger('prediction_model_version')->nullable()->after('predicted_wait_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropColumn(['predicted_wait_minutes', 'prediction_model_version']);
        });
    }
};
