<?php

namespace Tests\Feature;

use App\Models\MlModel;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\Ml\WaitTimePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaitTimePredictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-23 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function predictor(): WaitTimePredictor
    {
        return app(WaitTimePredictor::class);
    }

    public function test_it_falls_back_to_the_heuristic_when_no_model_is_trained(): void
    {
        $this->assertSame('heuristic', $this->predictor()->predict(today(), 4)['source']);
    }

    public function test_the_heuristic_multiplies_people_ahead_by_today_s_mean_consult_time(): void
    {
        $patient = User::factory()->patient()->create();

        /** Two finished consultations of 10 and 20 minutes: mean 15. */
        foreach ([[1, 10], [2, 20]] as $index => [$number, $minutes]) {
            QueueEntry::factory()->create([
                'patient_id' => $patient->id,
                'queue_date' => today(),
                'queue_number' => $number,
                'status' => 'done',
                'joined_at' => now()->copy()->subHours(2),
                'called_at' => now()->copy()->subHours(2),
                'completed_at' => now()->copy()->subHours(2)->addMinutes($minutes),
            ]);
        }

        $result = $this->predictor()->predict(today(), 3);

        $this->assertSame('heuristic', $result['source']);
        $this->assertSame(45, $result['minutes']);
    }

    public function test_the_heuristic_returns_zero_when_nobody_is_ahead(): void
    {
        $this->assertSame(0, $this->predictor()->predict(today(), 0)['minutes']);
    }

    public function test_it_uses_the_model_once_one_is_active(): void
    {
        MlModel::factory()->active()->create();

        $result = $this->predictor()->predict(today(), 4);

        $this->assertSame('model', $result['source']);
        $this->assertSame(1, $result['model_version']);
    }

    public function test_it_ignores_a_model_fitted_on_too_little_history(): void
    {
        MlModel::factory()->undertrained()->create();

        $this->assertSame('heuristic', $this->predictor()->predict(today(), 4)['source']);
    }

    /**
     * A model trained before an upgrade changed the feature list cannot be
     * fed today's inputs: the heuristic serves (instead of every queue
     * screen failing) until ml:train fits a new one.
     */
    public function test_it_ignores_a_model_fitted_on_an_older_feature_list(): void
    {
        $old = ['people_ahead', 'in_consultation', 'active_staff', 'people_ahead_per_staff'];
        MlModel::factory()->active()->create([
            'feature_names' => $old,
            'coefficients' => [1.0, 0.5, -1.5, 4.0],
            'feature_means' => [0.0, 0.0, 0.0, 0.0],
            'feature_stds' => [1.0, 1.0, 1.0, 1.0],
        ]);

        $this->assertSame('heuristic', $this->predictor()->predict(today(), 4)['source']);
    }

    public function test_nobody_ahead_and_nobody_with_the_nurse_is_zero_minutes(): void
    {
        MlModel::factory()->active()->create();

        $result = $this->predictor()->predict(today(), 0);

        $this->assertSame([0, 0, 0, 'model'], [$result['minutes'], $result['low'], $result['high'], $result['source']]);
    }

    public function test_the_prediction_always_sits_inside_its_own_interval(): void
    {
        MlModel::factory()->active()->create();

        foreach ([0, 1, 5, 20, 60] as $peopleAhead) {
            $result = $this->predictor()->predict(today(), $peopleAhead);

            $this->assertLessThanOrEqual($result['minutes'], $result['low'], "low for {$peopleAhead}");
            $this->assertLessThanOrEqual($result['high'], $result['minutes'], "high for {$peopleAhead}");
            $this->assertGreaterThanOrEqual(0, $result['low']);
        }
    }

    public function test_queue_mine_exposes_the_range_and_its_source(): void
    {
        MlModel::factory()->active()->create();

        $patient = User::factory()->patient()->create();

        QueueEntry::factory()->create([
            'patient_id' => User::factory()->patient()->create()->id,
            'queue_date' => today(),
            'queue_number' => 1,
            'status' => 'waiting',
            'joined_at' => now()->copy()->subMinutes(10),
        ]);

        QueueEntry::factory()->create([
            'patient_id' => $patient->id,
            'queue_date' => today(),
            'queue_number' => 2,
            'status' => 'waiting',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/queue/mine')
            ->assertOk()
            ->assertJsonPath('people_ahead', 1)
            ->assertJsonPath('estimate_source', 'model');

        $this->assertLessThanOrEqual($response->json('estimated_wait_minutes'), $response->json('estimated_wait_low'));
        $this->assertLessThanOrEqual($response->json('estimated_wait_high'), $response->json('estimated_wait_minutes'));
    }

    public function test_checking_in_records_the_prediction_shown_to_the_patient(): void
    {
        MlModel::factory()->active()->create();

        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/queue/check-in')->assertCreated();

        $entry = QueueEntry::where('patient_id', $patient->id)->firstOrFail();

        $this->assertNotNull($entry->predicted_wait_minutes);
        $this->assertSame(1, $entry->prediction_model_version);
    }

    public function test_a_check_in_without_a_model_records_the_heuristic_estimate_and_no_version(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/queue/check-in')->assertCreated();

        $entry = QueueEntry::where('patient_id', $patient->id)->firstOrFail();

        $this->assertSame(0, $entry->predicted_wait_minutes);
        $this->assertNull($entry->prediction_model_version);
    }
}
