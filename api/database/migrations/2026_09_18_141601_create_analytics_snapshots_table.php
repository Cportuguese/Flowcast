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
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('arrival_count')->default(0);
            $table->unsignedInteger('avg_wait_time')->nullable();
            $table->unsignedInteger('avg_consult_time')->nullable();
            $table->boolean('peak_hour')->default(false);
            $table->boolean('event_day')->default(false);
            $table->timestamps();

            $table->unique(['date', 'hour']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
    }
};
