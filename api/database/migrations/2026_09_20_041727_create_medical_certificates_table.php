<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            // One certificate per visit; re-issuing is a deliberate non-feature.
            $table->foreignId('visit_record_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('issued_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('certificate_no', 20)->unique();
            $table->unsignedSmallInteger('rest_days')->nullable();
            $table->text('remarks')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('file_path');
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_certificates');
    }
};
