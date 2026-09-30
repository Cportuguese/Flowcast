<?php

namespace Tests\Feature\Visits;

use App\Models\MedicalCertificate;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_by_school_id_returns_only_that_patients_visits_in_full(): void
    {
        $patient = User::factory()->patient()->create(['school_id' => '2024-00042']);
        $nurse = User::factory()->personnel()->create(['name' => 'Nurse Joy']);
        $this->visitWithCertificate($patient, $nurse);
        $this->visitWithCertificate(User::factory()->patient()->create(['school_id' => '2024-99999']), $nurse);

        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->getJson('/api/admin/visits/search?school_id=2024-00042')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.patient.school_id', '2024-00042')
            ->assertJsonPath('0.certificate.issued_by', 'Nurse Joy');
    }

    /**
     * formatVisit() reads four relations per row and the embedded certificate
     * reaches for its issuer. The listing eager-loads all of them, so the
     * number of queries must not grow with the number of visits returned.
     */
    public function test_search_query_count_does_not_grow_with_the_number_of_results(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());
        $patient = User::factory()->patient()->create(['school_id' => '2024-00042']);

        $this->visitWithCertificate($patient);
        $forOne = $this->countQueries(fn () => $this->getJson('/api/admin/visits/search?school_id=2024-00042')
            ->assertOk()
            ->assertJsonCount(1));

        foreach (range(1, 5) as $ignored) {
            $this->visitWithCertificate($patient);
        }
        $forSix = $this->countQueries(fn () => $this->getJson('/api/admin/visits/search?school_id=2024-00042')
            ->assertOk()
            ->assertJsonCount(6));

        $this->assertSame($forOne, $forSix);
    }

    private function visitWithCertificate(User $patient, ?User $issuer = null): VisitRecord
    {
        $entry = QueueEntry::factory()->for($patient, 'patient')->create();
        $visit = VisitRecord::factory()->for($entry, 'queueEntry')->create(['patient_id' => $patient->id]);

        MedicalCertificate::factory()->create([
            'visit_record_id' => $visit->id,
            'issued_by_id' => ($issuer ?? User::factory()->personnel()->create())->id,
        ]);

        return $visit;
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
