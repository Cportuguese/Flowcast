<?php

namespace Tests\Feature\Certificates;

use App\Models\MedicalCertificate;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicalCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_personnel_issues_a_certificate_and_the_pdf_is_stored(): void
    {
        $nurse = User::factory()->personnel()->create();
        $visit = VisitRecord::factory()->create();
        Sanctum::actingAs($nurse);

        $response = $this->postJson("/api/visits/{$visit->id}/certificate", [
            'rest_days' => 2,
            'remarks' => 'Hydrate and rest.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('certificate_no', 'MC-'.now()->year.'-0001')
            ->assertJsonPath('rest_days', 2)
            ->assertJsonPath('issued_by', $nurse->name)
            ->assertJsonPath('valid_from', $visit->created_at->toDateString())
            ->assertJsonPath('valid_until', $visit->created_at->copy()->addDay()->toDateString());

        $certificate = MedicalCertificate::sole();
        Storage::assertExists($certificate->file_path);
        $this->assertStringStartsWith('%PDF', Storage::get($certificate->file_path));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $nurse->id,
            'action' => 'certificate.issued',
            'model_id' => $certificate->id,
        ]);
    }

    public function test_certificate_numbers_are_sequential_within_a_year(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());

        $first = VisitRecord::factory()->create();
        $second = VisitRecord::factory()->create();

        $this->postJson("/api/visits/{$first->id}/certificate")->assertCreated();
        $this->postJson("/api/visits/{$second->id}/certificate")
            ->assertCreated()
            ->assertJsonPath('certificate_no', 'MC-'.now()->year.'-0002');
    }

    public function test_a_visit_gets_only_one_certificate(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());
        $visit = VisitRecord::factory()->create();

        $this->postJson("/api/visits/{$visit->id}/certificate")->assertCreated();
        $this->postJson("/api/visits/{$visit->id}/certificate")->assertStatus(409);

        $this->assertSame(1, MedicalCertificate::count());
    }

    public function test_no_rest_days_means_fit_to_return_with_no_end_date(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());
        $visit = VisitRecord::factory()->create();

        $this->postJson("/api/visits/{$visit->id}/certificate", ['rest_days' => 0])
            ->assertCreated()
            ->assertJsonPath('rest_days', null)
            ->assertJsonPath('valid_until', null);
    }

    public function test_admin_cannot_issue_but_can_download(): void
    {
        $certificate = MedicalCertificate::factory()->create();
        Storage::put($certificate->file_path, '%PDF-fake');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/visits/{$certificate->visit_record_id}/certificate")->assertForbidden();

        $this->get("/api/visits/{$certificate->visit_record_id}/certificate/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload($certificate->certificate_no.'.pdf');
    }

    public function test_patient_can_download_own_certificate_only(): void
    {
        $certificate = MedicalCertificate::factory()->create();
        Storage::put($certificate->file_path, '%PDF-fake');
        $other = MedicalCertificate::factory()->create();

        Sanctum::actingAs($certificate->patient);

        $this->getJson("/api/visits/{$certificate->visit_record_id}/certificate")
            ->assertOk()
            ->assertJsonPath('certificate_no', $certificate->certificate_no);
        $this->get("/api/visits/{$certificate->visit_record_id}/certificate/download")->assertOk();

        $this->getJson("/api/visits/{$other->visit_record_id}/certificate")->assertForbidden();
        $this->get("/api/visits/{$other->visit_record_id}/certificate/download")->assertForbidden();
    }

    public function test_download_regenerates_a_missing_file(): void
    {
        $certificate = MedicalCertificate::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        Storage::assertMissing($certificate->file_path);

        $this->get("/api/visits/{$certificate->visit_record_id}/certificate/download")->assertOk();

        Storage::assertExists($certificate->file_path);
    }

    public function test_visit_payload_embeds_the_certificate_once_issued(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());
        $visit = VisitRecord::factory()->create();

        $this->getJson("/api/visits/{$visit->queue_entry_id}")->assertOk()->assertJsonPath('certificate', null);

        $this->postJson("/api/visits/{$visit->id}/certificate")->assertCreated();

        $this->getJson("/api/visits/{$visit->queue_entry_id}")
            ->assertOk()
            ->assertJsonPath('certificate.certificate_no', 'MC-'.now()->year.'-0001')
            ->assertJsonPath('certificate.download_url', "/visits/{$visit->id}/certificate/download");
    }

    public function test_certificate_is_not_issued_without_a_visit(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/visits/999/certificate')->assertNotFound();
    }
}
