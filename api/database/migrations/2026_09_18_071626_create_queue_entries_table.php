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
        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('queue_number');
            $table->date('queue_date');
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->enum('source', ['walk_in', 'appointment'])->default('walk_in');
            $table->enum('status', ['waiting', 'in_consultation', 'done', 'skipped'])->default('waiting');
            $table->foreignId('appointment_id')->nullable()->unique()->constrained('appointments')->nullOnDelete();
            $table->foreignId('handled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('joined_at');
            $table->dateTime('called_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['queue_date', 'queue_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
    }
};
