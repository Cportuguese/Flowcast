<?php

namespace Database\Seeders\Concerns;

use App\Models\User;
use App\Support\Udd;
use Illuminate\Support\Carbon;

/**
 * Invented UDD patients with a complete health record: Filipino names with
 * real (not initial) middle names, a birthdate that fits their year level,
 * a course and department in UDD's proportions, a UDD-format ID number and
 * a Pangasinan address. None of them are real people.
 *
 * All randomness goes through mt_rand(), so a seeder that calls mt_srand()
 * first reproduces the same patients on every run.
 */
trait SamplesPatients
{
    private const FEMALE_FIRST_NAMES = [
        'Maria', 'Ana', 'Kristine', 'Angelica', 'Patricia', 'Nicole', 'Bea', 'Camille', 'Andrea', 'Jasmine',
        'Princess', 'Kimberly', 'Shaira', 'Erika', 'Diane', 'Trisha', 'Hazel', 'Janelle', 'Bianca', 'Alyssa',
        'Ma. Cristina', 'Joanna', 'Mikaela', 'Rhea', 'Lovely',
    ];

    private const MALE_FIRST_NAMES = [
        'Jose', 'Mark', 'John Paul', 'Carlo', 'Joshua', 'Christian', 'Paolo', 'Miguel', 'Rafael', 'Adrian',
        'Jerome', 'Ralph', 'Kevin', 'Ivan', 'Gabriel', 'Jericho', 'Lorenzo', 'Vincent', 'Dominic', 'Francis',
        'Juan Carlos', 'Kenneth', 'Renz', 'Aldrin', 'Jayson',
    ];

    private const SURNAMES = [
        'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Villanueva', 'Ramos',
        'Castillo', 'De Guzman', 'Fernandez', 'Aquino', 'Navarro', 'Soriano', 'Domingo', 'Manalo', 'Gonzales', 'Rivera',
        'Perez', 'Morales', 'Estrada', 'Lopez', 'Pascual', 'Salazar', 'Tolentino', 'Vergara', 'Cabrera', 'Macaraeg',
        'Ferrer', 'Ocampo', 'Padilla', 'Marquez', 'Velasco', 'Gutierrez', 'Mercado', 'Rosario', 'Fajardo', 'Tamayo',
        'Dela Cruz', 'Sta. Maria', 'Abalos', 'Biagtan', 'Calimlim', 'Datuin', 'Erfe', 'Mamaril', 'Nabua', 'Sison',
    ];

    private const PLACES = [
        'Arellano St., Dagupan City', 'Perez Blvd., Dagupan City', 'Brgy. Bonuan Gueset, Dagupan City',
        'Brgy. Pantal, Dagupan City', 'Brgy. Lucao, Dagupan City', 'Brgy. Mayombo, Dagupan City',
        'Brgy. Tapuac, Dagupan City', 'Calasiao, Pangasinan', 'Mangaldan, Pangasinan', 'San Fabian, Pangasinan',
        'Binmaley, Pangasinan', 'Lingayen, Pangasinan', 'Mapandan, Pangasinan', 'Manaoag, Pangasinan',
        'San Jacinto, Pangasinan', 'Sta. Barbara, Pangasinan', 'Malasiqui, Pangasinan', 'Urdaneta City, Pangasinan',
        'San Carlos City, Pangasinan', 'Bayambang, Pangasinan',
    ];

    /**
     * Rough share of UDD's students per course: weight, and the age range
     * of students in it.
     *
     * @var array<string, array{0: int, 1: int, 2: int}>
     */
    private const COURSE_WEIGHTS = [
        'BSIT' => [14, 17, 23], 'BSCS' => [5, 17, 23],
        'BSCE' => [4, 17, 23], 'BSCpE' => [3, 17, 23], 'BSEE' => [2, 17, 23], 'BSECE' => [2, 17, 23],
        'BECEd' => [2, 17, 23], 'BEEd' => [4, 17, 23], 'BSEd' => [5, 17, 23], 'BSNEd' => [1, 17, 23],
        'BSA' => [5, 17, 23], 'BSBA' => [7, 17, 23],
        'BSTM' => [6, 17, 23], 'BSHM' => [6, 17, 23],
        'ABComm' => [3, 17, 23], 'BSPsych' => [4, 17, 23],
        'BSN' => [6, 17, 23],
        'BSCrim' => [9, 17, 24],
        'SHS' => [7, 15, 18], 'JHS' => [3, 11, 16], 'GS' => [1, 6, 11],
        'MBA' => [1, 25, 40], 'MAEd' => [1, 25, 45],
    ];

    /** @var array<string, bool> ID numbers already handed out this run */
    private array $usedIdNumbers = [];

    /**
     * One patient's `users` row (without timestamps or password), for a
     * stable sample index.
     *
     * @return array<string, mixed>
     */
    protected function samplePatientRow(int $index, string $email): array
    {
        $female = $index % 2 === 1;
        $firstNames = $female ? self::FEMALE_FIRST_NAMES : self::MALE_FIRST_NAMES;
        $first = $firstNames[mt_rand(0, count($firstNames) - 1)];
        $last = self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)];

        do {
            $middle = self::SURNAMES[mt_rand(0, count(self::SURNAMES) - 1)];
        } while ($middle === $last);

        $roll = mt_rand(1, 100);
        $type = $roll <= 88 ? 'student' : ($roll <= 94 ? 'faculty' : 'staff');

        if ($type === 'student') {
            $course = $this->weightedCourse();
            [, $minAge, $maxAge] = self::COURSE_WEIGHTS[$course];
            $department = Udd::departmentOfCourse($course);
            $birthdate = $this->birthdateAged($minAge, $maxAge);
            $schoolId = $this->studentIdNumber($birthdate, $minAge);
        } else {
            $course = null;
            $departments = array_values(array_diff(Udd::departmentCodes(), ['ADMIN', 'SPS']));
            $department = $type === 'staff' ? 'ADMIN' : $departments[mt_rand(0, count($departments) - 1)];
            $birthdate = $this->birthdateAged(26, 60);
            $schoolId = $this->uniqueId(sprintf('%s-%04d', $type === 'faculty' ? 'FAC' : 'EMP', mt_rand(1, 9999)));
        }

        return [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'name' => User::joinName($first, $middle, $last),
            'email' => $email,
            'role' => 'patient',
            'birthdate' => $birthdate->toDateString(),
            'sex' => $female ? 'female' : 'male',
            'home_address' => mt_rand(1, 250).' '.self::PLACES[mt_rand(0, count(self::PLACES) - 1)],
            'school_id' => $schoolId,
            'patient_type' => $type,
            'department' => $department,
            'course' => $course,
            'contact_no' => '09'.mt_rand(10, 99).mt_rand(1000000, 9999999),
        ];
    }

    private function weightedCourse(): string
    {
        $roll = mt_rand(1, array_sum(array_column(self::COURSE_WEIGHTS, 0)));

        foreach (self::COURSE_WEIGHTS as $course => [$weight]) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $course;
            }
        }

        return 'BSIT';
    }

    private function birthdateAged(int $minAge, int $maxAge): Carbon
    {
        return Carbon::today()->subYears(mt_rand($minAge, $maxAge))->subDays(mt_rand(0, 364));
    }

    /**
     * UDD's format, 23-1057-651: the two-digit year the student entered,
     * then two number groups. A student entered at about the youngest age
     * of their level.
     */
    private function studentIdNumber(Carbon $birthdate, int $entryAge): string
    {
        $entryYear = min($birthdate->year + $entryAge, Carbon::today()->year);

        return $this->uniqueId(sprintf('%02d-%04d-%03d', $entryYear % 100, mt_rand(1000, 1999), mt_rand(100, 999)));
    }

    private function uniqueId(string $candidate): string
    {
        while (isset($this->usedIdNumbers[$candidate])) {
            $candidate = substr($candidate, 0, -3).sprintf('%03d', mt_rand(100, 999));
        }

        $this->usedIdNumbers[$candidate] = true;

        return $candidate;
    }
}
