<?php

namespace Database\Factories;

use App\Models\MedicalCertificate;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalCertificate>
 */
class MedicalCertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'visit_record_id' => VisitRecord::factory(),
            // Mirrors the controller: patient is the visit's patient.
            'patient_id' => fn (array $attributes) => VisitRecord::find($attributes['visit_record_id'])->patient_id,
            'issued_by_id' => User::factory()->nurse(),
            'certificate_no' => 'MC-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'rest_days' => 1,
            'remarks' => null,
            'valid_from' => today(),
            'valid_until' => today(),
            'file_path' => 'certificates/test.pdf',
        ];
    }
}
