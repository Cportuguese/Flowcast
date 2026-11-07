<?php

namespace Tests\Feature\Seeders;

use App\Models\Appointment;
use App\Models\EventDay;
use App\Models\InventoryLog;
use App\Models\MedicalCertificate;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\User;
use App\Models\VisitRecord;
use Database\Seeders\AlphaSampleDataSeeder;
use Database\Seeders\DevUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The sample layer a panel sees: inventory figures that add up, visits
 * whose details agree, a certificate the demo patient can actually
 * download, an event day the forecast and booking honour, and a coming week
 * of bookings for all three professionals with some still under review.
 */
class AlphaSampleDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> ids of the visits arranged before seeding */
    private array $arrangedVisitIds = [];

    public function test_every_batch_reconciles_with_its_inventory_log(): void
    {
        $this->seedSampleData();

        $this->assertTrue(InventoryLog::whereNotNull('visit_record_id')->exists());

        foreach (MedicineBatch::all() as $batch) {
            $quantity = fn (string $type): int => (int) InventoryLog::where('medicine_batch_id', $batch->id)->where('type', $type)->sum('quantity');

            $this->assertSame($batch->quantity_received, $quantity(InventoryLog::TYPE_STOCK_IN), $batch->batch_number);
            $this->assertSame(
                $batch->quantity_remaining,
                $quantity(InventoryLog::TYPE_STOCK_IN) - $quantity(InventoryLog::TYPE_STOCK_OUT) - $quantity(InventoryLog::TYPE_DISPOSED),
                $batch->batch_number,
            );
        }
    }

    public function test_inventory_shows_low_stock_expiring_and_expired_batches(): void
    {
        $this->seedSampleData();

        $this->assertSame(
            ['Cetirizine 10 mg', 'Elastic Bandage 3 in', 'Hyoscine-N-Butylbromide 10 mg'],
            Medicine::all()->filter(fn (Medicine $medicine) => $medicine->currentStock() < $medicine->reorder_level)->pluck('name')->sort()->values()->all(),
        );
        $this->assertSame(
            ['Loperamide 2 mg', 'Povidone-Iodine 10% Solution'],
            $this->batchesWithStock()->whereDate('expiry_date', '>=', today())->whereDate('expiry_date', '<=', today()->addDays(30))->get()->pluck('medicine.name')->sort()->values()->all(),
        );
        $this->assertSame(
            ['Salbutamol 2.5 mg Nebule'],
            $this->batchesWithStock()->whereDate('expiry_date', '<', today())->get()->pluck('medicine.name')->all(),
        );
    }

    public function test_visit_details_agree_with_the_complaint(): void
    {
        $this->seedSampleData();
        $arranged = VisitRecord::whereIn('id', $this->arrangedVisitIds);

        $toothache = (clone $arranged)->where('complaint', 'Toothache')->sole();
        $this->assertStringStartsWith('Mefenamic acid 500 mg given', $toothache->intervention);
        $this->assertNotNull($toothache->remarks);
        $this->assertNotNull($toothache->blood_pressure);

        $fever = (clone $arranged)->where('complaint', 'Fever')->sole();
        $this->assertGreaterThanOrEqual(37.8, (float) $fever->temperature);

        $extraction = (clone $arranged)->where('complaint', 'Toothache, for extraction')->sole();
        $this->assertSame('Tooth extraction under local anesthesia.', $extraction->intervention);
    }

    public function test_demo_patients_fever_visit_has_a_certificate_only_the_clinic_hands_out(): void
    {
        Storage::fake();
        $this->seedSampleData();
        $patient = User::where('email', DevUsersSeeder::PATIENT_EMAIL)->sole();
        $fever = VisitRecord::where('patient_id', $patient->id)->where('complaint', 'Fever')->sole();
        $certificate = MedicalCertificate::where('visit_record_id', $fever->id)->sole();

        // The patient is not given it through the app...
        Sanctum::actingAs($patient);
        $this->get("/api/visits/{$fever->id}/certificate/download")->assertForbidden();

        // ...the nurse who issued it prints it at the clinic.
        Sanctum::actingAs(User::providers()->where('profession', 'nurse')->sole());
        $this->get("/api/visits/{$fever->id}/certificate/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(2, $certificate->rest_days);
        // Issued by whoever saw the patient: Nurse Portia.
        $this->assertSame('nurse', $certificate->issuedBy->profession);
    }

    public function test_demo_patient_history_includes_the_dentist(): void
    {
        $this->seedSampleData();
        $patient = User::where('email', DevUsersSeeder::PATIENT_EMAIL)->sole();

        $visits = VisitRecord::with('createdBy')->where('patient_id', $patient->id)->get();

        $this->assertCount(3, $visits);
        $dental = $visits->firstWhere('complaint', 'Dental check-up and cleaning');
        $this->assertSame('dentist', $dental->createdBy->profession);
        $this->assertNull($dental->queue_entry_id);
        $this->assertNotNull($dental->appointment_id);
    }

    public function test_flags_next_tuesday_as_an_event_day_and_keeps_bookings_off_it(): void
    {
        $this->seedSampleData();

        $this->assertSame('2026-10-13', EventDay::sole()->date->toDateString());
        $this->assertSame(0, Appointment::whereDate('scheduled_at', '2026-10-13')->count());
    }

    public function test_the_coming_week_has_bookings_for_all_three_with_some_under_review(): void
    {
        $this->seedSampleData();

        $upcoming = Appointment::with('provider')->where('scheduled_at', '>', now())->get();

        $this->assertEqualsCanonicalizing(['nurse', 'dentist', 'physician'], $upcoming->pluck('provider.profession')->unique()->values()->all());
        $this->assertGreaterThanOrEqual(4, $upcoming->where('status', 'pending')->count());
        $this->assertSame(1, $upcoming->where('status', 'declined')->count());
        // One patient per slot per professional.
        $this->assertSame(1, $upcoming->countBy(fn (Appointment $booking) => $booking->provider_id.'@'.$booking->scheduled_at)->max());
        // Every booking is on a day its professional works.
        $this->assertTrue($upcoming->where('provider.profession', 'dentist')->every(fn ($booking) => in_array($booking->scheduled_at->dayOfWeek, [2, 4], true)));
        $this->assertTrue($upcoming->where('provider.profession', 'physician')->every(fn ($booking) => $booking->scheduled_at->isFriday()));
    }

    public function test_a_first_time_app_user_waits_for_review_with_an_unchecked_id(): void
    {
        $this->seedSampleData();

        $newcomer = User::where('email', AlphaSampleDataSeeder::NEWCOMER_EMAIL)->sole();
        $this->assertTrue($newcomer->hasCompleteHealthRecord());
        $this->assertFalse($newcomer->hasVerifiedSchoolId());

        $request = Appointment::with('provider')->where('patient_id', $newcomer->id)->sole();
        $this->assertSame('pending', $request->status);
        $this->assertSame('nurse', $request->provider->profession);

        // The returning demo patient's card was checked long ago.
        $this->assertTrue(User::where('email', DevUsersSeeder::PATIENT_EMAIL)->sole()->hasVerifiedSchoolId());
    }

    public function test_the_demo_patient_sees_a_confirmed_dental_booking_with_people_ahead(): void
    {
        $this->seedSampleData();
        Sanctum::actingAs(User::where('email', DevUsersSeeder::PATIENT_EMAIL)->sole());

        $upcoming = collect($this->getJson('/api/appointments/mine')->assertOk()->json('upcoming'));

        $dental = $upcoming->firstWhere('provider.profession', 'dentist');
        $this->assertSame('booked', $dental['status']);
        $this->assertGreaterThanOrEqual(2, $dental['people_ahead']);

        $this->assertSame('pending', $upcoming->firstWhere('provider.profession', 'physician')['status']);
    }

    /**
     * The dev logins with the clinic's hours, a few sample patients and one
     * recent visit per complaint, then the seeder under test.
     */
    private function seedSampleData(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 07:00:00'));
        $this->seed(DevUsersSeeder::class);

        $nurse = User::providers()->where('profession', 'nurse')->sole();
        $dentist = User::providers()->where('profession', 'dentist')->sole();

        foreach (range(1, 6) as $index) {
            User::factory()->patient()->create(['email' => "demo.patient{$index}@flowcast.test"]);
        }

        $complaints = ['Fever', 'Headache', 'Cough and colds', 'Stomach ache', 'Toothache', 'Dysmenorrhea', 'Minor wound', 'Body pain'];

        foreach ($complaints as $daysAgo => $complaint) {
            $this->arrangedVisitIds[] = VisitRecord::factory()->create([
                'complaint' => $complaint,
                'created_by_id' => $nurse->id,
                'created_at' => now()->subDays($daysAgo + 2)->setTime(10, 0),
            ])->id;
        }

        $extraction = Appointment::factory()->create([
            'provider_id' => $dentist->id,
            'scheduled_at' => Carbon::parse('2026-10-01 09:00'),
            'status' => 'completed',
        ]);
        $this->arrangedVisitIds[] = VisitRecord::factory()->create([
            'queue_entry_id' => null,
            'appointment_id' => $extraction->id,
            'patient_id' => $extraction->patient_id,
            'created_by_id' => $dentist->id,
            'complaint' => 'Toothache, for extraction',
            'created_at' => Carbon::parse('2026-10-01 09:25'),
        ])->id;

        $this->seed(AlphaSampleDataSeeder::class);
    }

    private function batchesWithStock()
    {
        return MedicineBatch::with('medicine')->where('quantity_remaining', '>', 0);
    }
}
