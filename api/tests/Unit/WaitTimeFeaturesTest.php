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
            'activeStaff' => 2,
            'rollingConsultMean' => 9.0,
            'minutesSinceOpen' => 150,
            'minutesUntilClose' => 390,
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

    public function test_people_ahead_per_staff_divides_by_the_roster(): void
    {
        $this->assertSame(3.0, $this->build(['peopleAhead' => 6, 'activeStaff' => 2])['people_ahead_per_staff']);
        $this->assertSame(6.0, $this->build(['peopleAhead' => 6, 'activeStaff' => 1])['people_ahead_per_staff']);
    }

    public function test_a_zero_staff_roster_never_divides_by_zero(): void
    {
        $features = $this->build(['peopleAhead' => 6, 'activeStaff' => 0]);

        $this->assertSame(1.0, $features['active_staff']);
        $this->assertSame(6.0, $features['people_ahead_per_staff']);
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

    public function test_weekday_dummies_use_sunday_as_the_omitted_baseline(): void
    {
        $sunday = $this->build(['arrivedAt' => Carbon::parse('2026-09-20 10:00:00')]);

        for ($day = 1; $day <= 6; $day++) {
            $this->assertSame(0.0, $sunday["dow_{$day}"]);
        }

        /** 2026-09-23 is a Wednesday. */
        $wednesday = $this->build(['arrivedAt' => Carbon::parse('2026-09-23 10:00:00')]);

        $this->assertSame(1.0, $wednesday['dow_3']);
        $this->assertSame(0.0, $wednesday['dow_2']);
    }

    public function test_schedule_offsets_are_clamped_to_zero(): void
    {
        $beforeOpen = $this->build(['minutesSinceOpen' => -45, 'minutesUntilClose' => -10]);

        $this->assertSame(0.0, $beforeOpen['minutes_since_open']);
        $this->assertSame(0.0, $beforeOpen['minutes_until_close']);
    }

    public function test_it_carries_no_information_the_arriving_patient_could_not_have(): void
    {
        /**
         * Every input is something measurable while the patient stands at
         * the desk: the queue in front of them, who is on duty, how long
         * recent consultations took, and the clock. Nothing here can be
         * derived from how long their own wait turned out to be, which is
         * the leak that would make a holdout score meaningless.
         */
        $parameters = (new \ReflectionMethod(WaitTimeFeatures::class, 'build'))->getParameters();

        $this->assertSame([
            'arrivedAt',
            'peopleAhead',
            'inConsultation',
            'activeStaff',
            'rollingConsultMean',
            'minutesSinceOpen',
            'minutesUntilClose',
            'isEventDay',
            'isAppointment',
        ], array_map(fn (\ReflectionParameter $p): string => $p->getName(), $parameters));
    }
}
