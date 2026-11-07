<?php

namespace Tests\Feature;

use App\Models\QueueEntry;
use App\Services\Ml\WaitTimeFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What the wait-time model is trained on: only arrivals who had to wait for
 * someone, described only by what was true when they walked in.
 */
class WaitTimeTrainingSetTest extends TestCase
{
    use RefreshDatabase;

    private function entry(int $number, string $joined, string $called, ?string $done): QueueEntry
    {
        $at = fn (?string $time) => $time === null ? null : Carbon::parse("2026-10-07 {$time}");

        return QueueEntry::factory()->create([
            'queue_number' => $number,
            'queue_date' => Carbon::parse('2026-10-07'),
            'status' => $done ? 'done' : 'skipped',
            'joined_at' => $at($joined),
            'called_at' => $at($called),
            'completed_at' => $at($done),
        ]);
    }

    public function test_only_arrivals_who_waited_for_someone_are_learned_from(): void
    {
        $this->entry(1, '09:00:00', '09:00:00', '09:10:00'); // nurse free: seen at once
        $this->entry(2, '09:04:00', '09:10:00', '09:18:00'); // #1 was with the nurse
        $this->entry(3, '09:40:00', '09:40:00', '09:50:00'); // nurse free again

        $set = WaitTimeFeatures::trainingSet();

        $this->assertCount(1, $set['samples']);
        $this->assertSame([6.0], $set['targets']);
    }

    /**
     * A patient called the second they arrive has called_at equal to their
     * own arrival. They must not count as "in consultation" for themselves —
     * that leaked their zero wait into their own features.
     */
    public function test_a_patient_is_never_in_consultation_ahead_of_themselves(): void
    {
        $first = $this->entry(1, '09:00:00', '09:00:00', '09:10:00');
        $second = $this->entry(2, '09:04:00', '09:10:00', '09:18:00');
        $day = QueueEntry::all();

        $this->assertSame(0, WaitTimeFeatures::inConsultationAt($day, $first, $first->joined_at));
        $this->assertSame(1, WaitTimeFeatures::inConsultationAt($day, $second, $second->joined_at));

        $inConsultation = array_search('in_consultation', WaitTimeFeatures::FEATURE_NAMES, true);
        $this->assertSame(1.0, WaitTimeFeatures::trainingSet()['samples'][0][$inConsultation]);
    }
}
