<?php

namespace Tests\Unit;

use App\Services\Ml\WaitTimeFeatures;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class WaitTimeFeaturesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, float>
     */
    private function build(array $overrides = []): array
    {
        $defaults = [
            'arrivedAt' => Carbon::parse('2026-09-23 10:30:00'),
            'peopleAhead' => 6,
            'inConsultation' => 1,
            'rollingConsultMean' => 9.0,
            'bookingsDueNextHour' => 1,
            'minutesSinceOpen' => 150,
            'isEventDay' => false,
            'isAppointment' => false,
        ];

        return WaitTimeFeatures::build(...array_merge($defaults, $overrides));
    }

    public function test_the_feature_vector_matches_the_declared_order(): void
    {
        $this->assertSame(WaitTimeFeatures::FEATURE_NAMES, array_keys($this->build()));
    }

    public function test_the_ordering_is_stable_across_different_inputs(): void
    {
        $this->assertSame(
            array_keys($this->build()),
            array_keys($this->build([
                'arrivedAt' => Carbon::parse('2026-09-20 16:05:00'),
                'peopleAhead' => 0,
                'isEventDay' => true,
                'isAppointment' => true,
            ])),
        );
    }

    public function test_every_value_is_a_float_so_the_matrix_is_numeric(): void
    {
        foreach ($this->build(['isEventDay' => true, 'isAppointment' => true]) as $name => $value) {
            $this->assertIsFloat($value, "feature [{$name}]");
        }
    }

    public function test_the_old_formula_is_one_of_the_inputs(): void
    {
        /** People ahead x the recent pace, never under a minute a head. */
        $this->assertSame(54.0, $this->build(['peopleAhead' => 6, 'rollingConsultMean' => 9.0])['queue_minutes_ahead']);
        $this->assertSame(6.0, $this->build(['peopleAhead' => 6, 'rollingConsultMean' => 0.2])['queue_minutes_ahead']);
        $this->assertSame(6.0, $this->build(['peopleAhead' => 6])['people_ahead']);
    }

    public function test_bookings_due_next_hour_is_carried_as_given(): void
    {
        $this->assertSame(3.0, $this->build(['bookingsDueNextHour' => 3])['bookings_due_next_hour']);
        $this->assertSame(0.0, $this->build(['bookingsDueNextHour' => 0])['bookings_due_next_hour']);
    }

    public function test_due_bookings_count_only_the_hour_after_arrival(): void
    {
        $arrival = Carbon::parse('2026-09-23 10:00:00');
        $at = fn (string $time): int => Carbon::parse("2026-09-23 {$time}")->getTimestamp();

        /**
         * A booking at the very moment of arrival is already due, not
         * coming; one exactly an hour out is still inside the window.
         */
        $this->assertSame(2, WaitTimeFeatures::countDueWithin([
            $at('09:40:00'),
            $at('10:00:00'),
            $at('10:20:00'),
            $at('11:00:00'),
            $at('11:01:00'),
        ], $arrival));
    }

    public function test_the_staff_count_and_closing_features_are_gone(): void
    {
        /** UDD runs one nurse, so a staff count never varies. */
        $this->assertNotContains('active_staff', WaitTimeFeatures::FEATURE_NAMES);
        $this->assertNotContains('people_ahead_per_staff', WaitTimeFeatures::FEATURE_NAMES);
        /** Every day runs 8 to 6, so this was always 600 minus minutes_since_open. */
        $this->assertNotContains('minutes_until_close', WaitTimeFeatures::FEATURE_NAMES);
    }

    public function test_nobody_ahead_and_nobody_with_the_nurse_means_no_wait_to_predict(): void
    {
        $this->assertTrue(WaitTimeFeatures::isWalkStraightIn(0, 0));
        $this->assertFalse(WaitTimeFeatures::isWalkStraightIn(0, 1));
        $this->assertFalse(WaitTimeFeatures::isWalkStraightIn(2, 0));
    }

    public function test_the_hour_is_encoded_cyclically(): void
    {
        $midnight = $this->build(['arrivedAt' => Carbon::parse('2026-09-23 00:00:00')]);
        $justBefore = $this->build(['arrivedAt' => Carbon::parse('2026-09-23 23:59:00')]);

        /** 23:59 and 00:00 are a minute apart and must encode as such. */
        $this->assertEqualsWithDelta($midnight['hour_sin'], $justBefore['hour_sin'], 0.01);
        $this->assertEqualsWithDelta($midnight['hour_cos'], $justBefore['hour_cos'], 0.01);
        $this->assertEqualsWithDelta(1.0, ($midnight['hour_sin'] ** 2) + ($midnight['hour_cos'] ** 2), 1e-9);
    }

    public function test_weekday_dummies_use_monday_as_the_omitted_baseline(): void
    {
        /** 2026-09-21 is a Monday; Sunday never trains and reads the same. */
        foreach (['2026-09-21 10:00:00', '2026-09-20 10:00:00'] as $baselineDay) {
            $features = $this->build(['arrivedAt' => Carbon::parse($baselineDay)]);

            for ($day = 2; $day <= 6; $day++) {
                $this->assertSame(0.0, $features["dow_{$day}"]);
            }
        }

        $this->assertNotContains('dow_1', WaitTimeFeatures::FEATURE_NAMES);

        /** 2026-09-23 is a Wednesday. */
        $wednesday = $this->build(['arrivedAt' => Carbon::parse('2026-09-23 10:00:00')]);

        $this->assertSame(1.0, $wednesday['dow_3']);
        $this->assertSame(0.0, $wednesday['dow_2']);
    }

    public function test_minutes_since_open_is_clamped_to_zero(): void
    {
        $this->assertSame(0.0, $this->build(['minutesSinceOpen' => -45])['minutes_since_open']);
    }

    public function test_it_carries_no_information_the_arriving_patient_could_not_have(): void
    {
        /**
         * Every input is something measurable while the patient stands at
         * the desk: the queue in front of them, how long recent
         * consultations took, the bookings already on the nurse's
         * schedule, and the clock. Nothing here can be derived from how
         * long their own wait turned out to be, which is the leak that would
         * make a holdout score meaningless.
         */
        $parameters = (new \ReflectionMethod(WaitTimeFeatures::class, 'build'))->getParameters();

        $this->assertSame([
            'arrivedAt',
            'peopleAhead',
            'inConsultation',
            'rollingConsultMean',
            'bookingsDueNextHour',
            'minutesSinceOpen',
            'isEventDay',
            'isAppointment',
        ], array_map(fn (\ReflectionParameter $p): string => $p->getName(), $parameters));
    }
}
