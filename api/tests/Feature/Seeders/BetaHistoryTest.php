<?php

namespace Tests\Feature\Seeders;

use App\Models\Appointment;
use App\Models\InventoryLog;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Database\Seeders\AlphaSampleDataSeeder;
use Database\Seeders\DemoHistorySeeder;
use Database\Seeders\DevUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What the beta dataset adds to the alpha one: a history long enough for
 * patients to enrol part-way through it, and an inventory that was stocked
 * all along rather than only in the last few months.
 */
class BetaHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_only_has_visits_while_enrolled(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 07:00:00'));

        $seeder = new DemoHistorySeeder;
        $seeder->days = 230;
        $seeder->patientCount = 250;
        $seeder->enrollmentAware = true;
        $this->app->instance(DemoHistorySeeder::class, $seeder);
        $this->seed(DemoHistorySeeder::class);

        $this->assertSame(250, User::where('email', 'like', 'demo.patient%')->count());

        $students = User::where('email', 'like', 'demo.patient%')->where('patient_type', 'student')->get()->keyBy('id');
        $checked = 0;
        $enteredThisYear = 0;

        foreach ([...QueueEntry::all(['patient_id', 'queue_date'])->map(fn ($e) => [$e->patient_id, $e->queue_date]), ...Appointment::all(['patient_id', 'scheduled_at'])->map(fn ($a) => [$a->patient_id, $a->scheduled_at])] as [$patientId, $when]) {
            $student = $students->get($patientId);

            if (! $student) {
                continue; // faculty and staff are always enrolled
            }

            $entry = 2000 + (int) substr($student->school_id, 0, 2);

            // A graduate student's ID can date from long before the history
            // begins; those are simply enrolled throughout.
            if ($entry + 6 < now()->subDays(230)->year) {
                continue;
            }

            $this->assertGreaterThanOrEqual(Carbon::create($entry, 7, 1)->startOfDay(), $when->copy()->startOfDay(), "{$student->school_id} on {$when}");
            $this->assertLessThanOrEqual(Carbon::create($entry + 6, 12, 31)->endOfDay(), $when);
            $checked++;
            $enteredThisYear += $entry === 2026 ? 1 : 0;
        }

        $this->assertGreaterThan(1000, $checked);
        // The rule has teeth: this year's entrants do appear, but only from July.
        $this->assertGreaterThan(0, $enteredThisYear);
    }

    public function test_accounts_are_dated_to_when_each_patient_enrolled(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 07:00:00'));

        $seeder = new DemoHistorySeeder;
        $seeder->days = 40;
        $seeder->patientCount = 200;
        $seeder->enrollmentAware = true;
        $this->app->instance(DemoHistorySeeder::class, $seeder);
        $this->seed(DemoHistorySeeder::class);

        $patients = User::where('email', 'like', 'demo.patient%')->get();
        $start = now()->subDays(40)->startOfDay();

        // Not all made on the day of seeding, and every account exists before the history begins.
        $this->assertGreaterThan(30, $patients->map(fn (User $u) => $u->created_at->toDateString())->unique()->count());
        $this->assertTrue($patients->every(fn (User $u) => $u->created_at->lt($start)));

        // A student's account dates from the June they entered; anyone who was
        // already there when the history begins registered in the 60 days before.
        foreach ($patients->where('patient_type', 'student') as $student) {
            $entry = 2000 + (int) substr($student->school_id, 0, 2);

            if ($entry + 6 < $start->year) {
                $this->assertTrue($student->created_at->gte($start->copy()->subDays(60)), $student->school_id);

                continue;
            }

            $this->assertTrue($student->created_at->gte(Carbon::create($entry, 6, 1)->max($start->copy()->subDays(60))), $student->school_id);
        }

        // Their ID check dates from then too, not from today.
        $this->assertTrue($patients->every(fn (User $u) => $u->school_id_verified_at->equalTo($u->created_at)));
    }

    public function test_the_alpha_history_is_unchanged_without_enrollment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 07:00:00'));

        $seeder = new DemoHistorySeeder;
        $seeder->days = 21;
        $this->app->instance(DemoHistorySeeder::class, $seeder);
        $this->seed(DemoHistorySeeder::class);

        $this->assertSame(240, User::where('email', 'like', 'demo.patient%')->count());
        // Created on the day of seeding, as before.
        $this->assertSame(1, User::where('email', 'like', 'demo.patient%')->get()->map(fn (User $u) => $u->created_at->toDateString())->unique()->count());
    }

    public function test_a_stocked_history_reconciles_batch_by_batch(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 07:00:00'));
        $this->seed(DevUsersSeeder::class);

        $nurse = User::providers()->where('profession', 'nurse')->sole();

        foreach (range(1, 6) as $index) {
            User::factory()->patient()->create(['email' => "demo.patient{$index}@flowcast.test"]);
        }

        // A visit about every week for fourteen months, all of them fevers or colds.
        foreach (range(1, 60) as $week) {
            VisitRecord::factory()->create([
                'complaint' => $week % 2 ? 'Fever' : 'Cough and colds',
                'created_by_id' => $nurse->id,
                'created_at' => now()->subDays($week * 7)->setTime(10, 0),
            ]);
        }

        $seeder = new AlphaSampleDataSeeder;
        $seeder->dispensingWindowDays = 430;
        $seeder->certificateWindowDays = 430;
        $seeder->restockHistory = true;
        $this->app->instance(AlphaSampleDataSeeder::class, $seeder);
        $this->seed(AlphaSampleDataSeeder::class);

        foreach (MedicineBatch::all() as $batch) {
            $quantity = fn (string $type): int => (int) InventoryLog::where('medicine_batch_id', $batch->id)->where('type', $type)->sum('quantity');

            $this->assertGreaterThan(0, $batch->quantity_received, "{$batch->batch_number} was delivered with nothing in it");
            $this->assertSame($batch->quantity_received, $quantity(InventoryLog::TYPE_STOCK_IN), $batch->batch_number);
            $this->assertSame(
                $batch->quantity_remaining,
                $quantity(InventoryLog::TYPE_STOCK_IN) - $quantity(InventoryLog::TYPE_STOCK_OUT) - $quantity(InventoryLog::TYPE_DISPOSED),
                $batch->batch_number,
            );
        }

        // Stocked all along: deliveries through the whole window, and dispensing from the old ones.
        $paracetamol = Medicine::where('name', 'Paracetamol 500 mg')->sole();
        $this->assertGreaterThanOrEqual(4, MedicineBatch::where('medicine_id', $paracetamol->id)->count());
        $this->assertTrue(InventoryLog::where('type', InventoryLog::TYPE_STOCK_OUT)->where('created_at', '<', now()->subDays(300))->exists());

        // Old batches were used up, so today's alerts are the same as with the short history.
        $this->assertSame(
            ['Cetirizine 10 mg', 'Elastic Bandage 3 in', 'Hyoscine-N-Butylbromide 10 mg'],
            Medicine::all()->filter(fn (Medicine $medicine) => $medicine->currentStock() < $medicine->reorder_level)->pluck('name')->sort()->values()->all(),
        );
        $this->assertSame(
            ['Salbutamol 2.5 mg Nebule'],
            MedicineBatch::with('medicine')->where('quantity_remaining', '>', 0)->whereDate('expiry_date', '<', today())->get()->pluck('medicine.name')->all(),
        );
    }
}
