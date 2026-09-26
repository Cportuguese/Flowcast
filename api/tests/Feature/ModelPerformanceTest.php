<?php

namespace Tests\Feature;

use App\Models\MlModel;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\Ml\WaitTimeFeatures;
use App\Services\Ml\WaitTimePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModelPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_is_admin_only(): void
    {
        foreach (['patient', 'personnel'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            $this->getJson('/api/analytics/model-performance')->assertForbidden();
        }
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/analytics/model-performance')->assertUnauthorized();
    }

    public function test_it_reports_an_honest_empty_state_before_any_model_is_trained(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/analytics/model-performance')
            ->assertOk()
            ->assertJsonPath('wait_time.is_trained', false)
            ->assertJsonPath('wait_time.version', null)
            ->assertJsonPath('wait_time.metrics', null)
            ->assertJsonPath('wait_time.training_rows', 0)
            ->assertJsonPath('wait_time.feature_importances', [])
            ->assertJsonPath('volume.is_trained', false)
            ->assertJsonPath('online.points', [])
            ->assertJsonPath('online.metrics.n', 0);
    }

    public function test_it_reports_metrics_and_feature_importances_for_an_active_model(): void
    {
        MlModel::factory()->active()->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/analytics/model-performance')
            ->assertOk()
            ->assertJsonPath('wait_time.is_trained', true)
            ->assertJsonPath('wait_time.version', 1)
            ->assertJsonPath('wait_time.algorithm', 'ridge_regression')
            ->assertJsonPath('wait_time.metrics.mae', 4.2);

        $importances = $response->json('wait_time.feature_importances');

        $this->assertCount(count(WaitTimeFeatures::FEATURE_NAMES), $importances);
        $this->assertArrayHasKey('feature', $importances[0]);
        $this->assertArrayHasKey('coefficient', $importances[0]);
    }

    public function test_online_accuracy_compares_recorded_predictions_against_real_waits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00'));

        $patient = User::factory()->patient()->create();

        QueueEntry::factory()->create([
            'patient_id' => $patient->id,
            'queue_date' => today(),
            'queue_number' => 1,
            'status' => 'done',
            'joined_at' => now()->copy()->subMinutes(30),
            'called_at' => now()->copy()->subMinutes(10),
            'predicted_wait_minutes' => 15,
            'prediction_model_version' => 1,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/analytics/model-performance')
            ->assertOk()
            ->assertJsonPath('online.metrics.n', 1)
            /** Predicted 15, actually waited 20 — five minutes out. */
            ->assertJsonPath('online.points.0.predicted', 15)
            ->assertJsonPath('online.points.0.actual', 20)
            ->assertJsonPath('online.metrics.mae', 5);
    }

    public function test_the_staffing_what_if_shows_the_wait_falling_as_staff_rise(): void
    {
        MlModel::factory()->active()->create();

        Sanctum::actingAs(User::factory()->personnel()->create());

        $response = $this->getJson('/api/analytics/staffing-what-if?people_ahead=10')->assertOk();
        $scenarios = $response->json('scenarios');

        $this->assertSame([1, 2, 3], array_column($scenarios, 'active_staff'));

        foreach ($scenarios as $scenario) {
            $this->assertLessThanOrEqual($scenario['estimated_wait_high'], $scenario['estimated_wait_minutes']);
            $this->assertLessThanOrEqual($scenario['estimated_wait_minutes'], $scenario['estimated_wait_low']);
        }

        /** people_ahead_per_staff falls as staff rise, so the wait must too. */
        $this->assertLessThan($scenarios[0]['estimated_wait_minutes'], $scenarios[1]['estimated_wait_minutes']);
        $this->assertLessThan($scenarios[1]['estimated_wait_minutes'], $scenarios[2]['estimated_wait_minutes']);
    }

    public function test_the_staffing_what_if_is_closed_to_patients(): void
    {
        Sanctum::actingAs(User::factory()->patient()->create());

        $this->getJson('/api/analytics/staffing-what-if')->assertForbidden();
    }

    public function test_an_undertrained_model_is_not_served(): void
    {
        MlModel::factory()->undertrained()->create();

        $this->assertNull(app(WaitTimePredictor::class)->activeModel());
    }
}
