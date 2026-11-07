<?php

namespace Database\Seeders\Concerns;

/**
 * Invented clinical cases shared by the sample-data seeders, so a sample
 * visit's complaint, intervention, remarks, vital signs and dispensed
 * medicines always agree with one another instead of being drawn
 * independently. None of it is real patient data.
 *
 * The record says what was DONE (the intervention), not a diagnosis: the
 * clinic nurse does not diagnose.
 *
 * All randomness goes through mt_rand(), so a seeder that calls
 * mt_srand() first reproduces the same records on every run.
 */
trait SamplesClinicalCases
{
    /**
     * What walk-ins bring to the nurse (the same complaints DemoHistorySeeder
     * writes for the queue).
     *
     * @var array<int, string>
     */
    protected const NURSE_COMPLAINTS = [
        'Headache', 'Fever', 'Sore throat', 'Stomach ache', 'Dizziness', 'Cough and colds',
        'Dysmenorrhea', 'Minor wound', 'Toothache', 'Body pain', 'Allergic reaction', 'Eye irritation',
    ];

    /**
     * Why patients book Nurse Portia ahead instead of walking in. A booked
     * patient's visit is recorded under this reason.
     *
     * @var array<int, string>
     */
    protected const NURSE_BOOKING_REASONS = [
        'Blood pressure monitoring', 'Follow-up check-up', 'Wound dressing follow-up', 'Medical certificate for enrollment',
    ];

    /** @var array<int, string> */
    protected const DENTIST_COMPLAINTS = [
        'Dental check-up and cleaning', 'Toothache, for extraction', 'Tooth cavity', 'Bleeding gums', 'Broken tooth',
    ];

    /** @var array<int, string> */
    protected const PHYSICIAN_COMPLAINTS = [
        'Medical clearance', 'Persistent cough', 'Recurring headaches', 'Blood pressure follow-up', 'Skin rash', 'Fever for three days',
    ];

    /**
     * Cases per complaint. Each case is [intervention, remarks,
     * [medicine => quantity], referral or null, relative weight, febrile].
     *
     * @return array<string, array<int, array{0: string, 1: string, 2: array<string, int>, 3: string|null, 4: float, 5: bool}>>
     */
    protected function clinicalCases(): array
    {
        return [
            // The nurse's walk-ins.
            'Headache' => [
                ['Paracetamol 500 mg given; rested in the clinic for 30 minutes.', 'Headache relieved. Sent back to class.', ['Paracetamol 500 mg' => 2], null, 3.0, false],
                ['Mefenamic acid 500 mg given; advised to rest in a dark, quiet room.', 'Recurring headaches: advised to see the school physician on Friday.', ['Mefenamic Acid 500 mg' => 1], 'School physician (Friday consultation)', 1.0, false],
            ],
            'Fever' => [
                ['Paracetamol 500 mg given; tepid sponge bath; increased fluid intake.', 'Sent home to rest. Return if fever persists beyond 3 days.', ['Paracetamol 500 mg' => 4], null, 3.0, true],
                ['Paracetamol 500 mg given; advised rest and plenty of fluids.', 'Sent home; parent informed.', ['Paracetamol 500 mg' => 3], null, 2.0, true],
                ['Paracetamol 500 mg given; temperature rechecked after 30 minutes.', 'Advised CBC and dengue test today.', ['Paracetamol 500 mg' => 3], 'Outside laboratory: CBC with platelet count and dengue NS1 test', 0.5, true],
            ],
            'Sore throat' => [
                ['Throat lozenges given; warm saline gargle advised.', 'Sent back to class. Return if fever develops.', ['Throat Lozenge' => 4], null, 3.0, false],
                ['Paracetamol 500 mg and throat lozenges given.', 'Advised to see the school physician if not better in 2 days.', ['Paracetamol 500 mg' => 2, 'Throat Lozenge' => 4], null, 1.0, true],
            ],
            'Stomach ache' => [
                ['Antacid tablets given; advised small, frequent meals and no spicy food.', 'Pain eased. Sent back to class.', ['Aluminum Hydroxide + Magnesium Hydroxide' => 2], null, 3.0, false],
                ['Loperamide 2 mg and oral rehydration salts given.', 'Sent home; watch for signs of dehydration.', ['Loperamide 2 mg' => 2, 'Oral Rehydration Salts' => 2], null, 1.5, false],
                ['Hyoscine-N-butylbromide 10 mg given; rested in the clinic.', 'Cramps relieved after an hour. Sent back to class.', ['Hyoscine-N-Butylbromide 10 mg' => 2], null, 1.0, false],
            ],
            'Dizziness' => [
                ['Oral rehydration salts given; rested in the clinic; blood pressure rechecked.', 'Felt better after resting. Advised to eat breakfast.', ['Oral Rehydration Salts' => 2], null, 2.0, false],
                ['Meclizine 25 mg given; advised to avoid sudden head movements.', 'Sent home to rest.', ['Meclizine 25 mg' => 2], null, 1.0, false],
            ],
            'Cough and colds' => [
                ['Lagundi tablets given for the cough; advised rest and plenty of fluids.', 'Sent back to class.', ['Lagundi 600 mg' => 6], null, 3.0, true],
                ['Cetirizine 10 mg given once daily.', 'Allergic rhinitis suspected; avoid dust.', ['Cetirizine 10 mg' => 3], null, 1.5, false],
                ['Salbutamol nebulization given in the clinic; breathing improved.', 'Known asthmatic. Sent home to rest.', ['Salbutamol 2.5 mg Nebule' => 1], null, 0.7, false],
                ['Lagundi tablets given; chest X-ray requested.', 'Cough for over 2 weeks.', ['Lagundi 600 mg' => 6], 'Diagnostic center: chest X-ray (PA view)', 0.4, true],
            ],
            'Dysmenorrhea' => [
                ['Mefenamic acid 500 mg given; warm compress on the lower abdomen.', 'Rested for an hour, then sent back to class.', ['Mefenamic Acid 500 mg' => 3], null, 3.0, false],
                ['Hyoscine-N-butylbromide 10 mg given; rested in the clinic.', 'Sent home to rest.', ['Hyoscine-N-Butylbromide 10 mg' => 2], null, 1.0, false],
            ],
            'Minor wound' => [
                ['Wound cleaned with povidone-iodine and dressed.', 'Keep dry; change the dressing daily.', ['Sterile Gauze Pad 4x4' => 2, 'Adhesive Bandage' => 2], null, 3.0, false],
                ['Wound cleaned and dressed; bleeding controlled.', 'Deep cut; referred for possible suturing.', ['Sterile Gauze Pad 4x4' => 3], 'Hospital emergency room: wound evaluation for suturing', 0.6, false],
            ],
            'Toothache' => [
                ['Mefenamic acid 500 mg given for the pain.', 'Booked with the clinic dentist (Tue/Thu).', ['Mefenamic Acid 500 mg' => 3], 'Clinic dentist', 1.0, false],
                ['Mefenamic acid 500 mg given; warm saline mouth rinse advised.', 'Gum swelling. Return if it worsens.', ['Mefenamic Acid 500 mg' => 3], null, 1.0, false],
            ],
            'Body pain' => [
                ['Ibuprofen 400 mg given after a meal; cold compress applied.', 'Avoid strenuous activity for 2 days.', ['Ibuprofen 400 mg' => 3], null, 3.0, false],
                ['Elastic bandage applied; rest, ice, compression and elevation.', 'Mild ankle sprain. Excused from PE for 3 days.', ['Elastic Bandage 3 in' => 1, 'Ibuprofen 400 mg' => 2], null, 0.8, false],
            ],
            'Allergic reaction' => [
                ['Cetirizine 10 mg given; advised to avoid the suspected trigger.', 'Rashes subsided after an hour.', ['Cetirizine 10 mg' => 2], null, 1.0, false],
            ],
            'Eye irritation' => [
                ['Eyes irrigated with sterile saline.', 'Advised not to rub the eyes.', [], null, 2.0, false],
                ['Eye irrigated with sterile saline; foreign body removed.', 'Sent back to class.', [], null, 1.0, false],
            ],

            // The nurse's booked patients.
            'Blood pressure monitoring' => [
                ['Blood pressure taken and recorded.', 'Within normal range. Continue weekly monitoring.', [], null, 3.0, false],
                ['Blood pressure taken twice, 10 minutes apart.', 'Slightly elevated; advised to see the school physician on Friday.', [], 'School physician (Friday consultation)', 1.0, false],
            ],
            'Follow-up check-up' => [
                ['Reassessed; vital signs taken.', 'Improving. No further visit needed.', [], null, 3.0, false],
            ],
            'Wound dressing follow-up' => [
                ['Wound cleaned and redressed.', 'Healing well. Next dressing in 2 days.', ['Sterile Gauze Pad 4x4' => 1, 'Adhesive Bandage' => 1], null, 1.0, false],
            ],
            'Medical certificate for enrollment' => [
                ['Health assessment done; vital signs taken.', 'Fit to enroll.', [], null, 1.0, false],
            ],

            // The dentist's booked patients (Tuesday and Thursday).
            'Dental check-up and cleaning' => [
                ['Oral examination; oral prophylaxis (cleaning) done.', 'Advised to brush twice daily and floss. Next check-up in 6 months.', [], null, 3.0, false],
            ],
            'Toothache, for extraction' => [
                ['Tooth extraction under local anesthesia.', 'Bite on gauze for 30 minutes; soft diet today.', ['Mefenamic Acid 500 mg' => 3, 'Sterile Gauze Pad 4x4' => 2], null, 2.0, false],
            ],
            'Tooth cavity' => [
                ['Temporary filling placed.', 'Return in 2 weeks for the permanent filling.', [], null, 2.0, false],
                ['Cavity cleaned; permanent filling done.', 'Avoid chewing on that side for a day.', [], null, 1.0, false],
            ],
            'Bleeding gums' => [
                ['Oral examination; scaling done.', 'Gingivitis: warm saline rinse twice daily.', [], null, 1.0, false],
            ],
            'Broken tooth' => [
                ['Sharp edges smoothened; temporary restoration placed.', 'Avoid hard food; return Thursday.', [], null, 1.0, false],
                ['Oral examination; X-ray requested.', 'Root may be involved.', [], 'Dental clinic: periapical X-ray', 0.5, false],
            ],

            // The school physician's booked patients (Friday afternoon).
            'Medical clearance' => [
                ['Physical examination done.', 'Fit for physical activities (PATHFIT / OJT).', [], null, 3.0, false],
            ],
            'Persistent cough' => [
                ['Examined; prescribed a 7-day antibiotic course.', 'Follow up next Friday if not better.', ['Lagundi 600 mg' => 6], null, 2.0, false],
                ['Examined; chest X-ray requested.', 'Cough for 3 weeks.', [], 'Diagnostic center: chest X-ray (PA view)', 1.0, false],
            ],
            'Recurring headaches' => [
                ['Examined; blood pressure monitoring started.', 'Keep a headache diary; avoid skipping meals.', ['Paracetamol 500 mg' => 4], null, 2.0, false],
                ['Examined; eye check advised.', 'Possible eye strain.', [], 'Ophthalmologist: refraction', 1.0, false],
            ],
            'Blood pressure follow-up' => [
                ['Blood pressure rechecked; within normal range.', 'Continue lifestyle changes; recheck in a month.', [], null, 2.0, false],
            ],
            'Skin rash' => [
                ['Examined; antihistamine given.', 'Contact dermatitis; avoid the irritant.', ['Cetirizine 10 mg' => 3], null, 2.0, false],
            ],
            'Fever for three days' => [
                ['Examined; CBC and dengue test requested; paracetamol given.', 'Rest at home; return with the results.', ['Paracetamol 500 mg' => 6], 'Outside laboratory: CBC with platelet count and dengue NS1 test', 2.0, true],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function complaints(): array
    {
        return self::NURSE_COMPLAINTS;
    }

    /**
     * A weighted random case for the complaint (an unknown complaint is
     * treated as a headache).
     *
     * @return array{intervention: string, remarks: string, medicines: array<string, int>, referral: string|null, febrile: bool}
     */
    protected function caseFor(string $complaint): array
    {
        $cases = $this->clinicalCases()[$complaint] ?? $this->clinicalCases()['Headache'];
        $roll = (mt_rand() / mt_getrandmax()) * array_sum(array_column($cases, 4));
        $chosen = $cases[array_key_last($cases)];

        foreach ($cases as $case) {
            $roll -= $case[4];

            if ($roll <= 0) {
                $chosen = $case;

                break;
            }
        }

        return [
            'intervention' => $chosen[0],
            'remarks' => $chosen[1],
            'medicines' => $chosen[2],
            'referral' => $chosen[3],
            'febrile' => $chosen[5],
        ];
    }

    /**
     * Plausible vital signs, febrile when the case comes with a fever.
     *
     * @return array{blood_pressure: string, temperature: float, pulse: int, resp_rate: int}
     */
    protected function vitalsFor(bool $febrile, string $complaint = ''): array
    {
        $asthmatic = str_contains($complaint, 'Cough');

        return [
            'blood_pressure' => mt_rand(100, 132).'/'.mt_rand(62, 86),
            'temperature' => $febrile ? mt_rand(378, 392) / 10 : mt_rand(363, 373) / 10,
            'pulse' => mt_rand(66, 92) + ($febrile ? 12 : 0),
            'resp_rate' => mt_rand(14, 19) + ($asthmatic ? 2 : 0),
        ];
    }

    protected function chance(float $probability): bool
    {
        return (mt_rand() / mt_getrandmax()) < $probability;
    }
}
