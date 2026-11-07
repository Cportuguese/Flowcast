<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use App\Support\Udd;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "How many BSIT students came today, and how many STE were skipped?" —
 * clinic activity counted by the patient's department and course, plus how
 * each of the three professionals' days went.
 *
 * - visited  = checked into the nurse's queue, or seen by the dentist or
 *              the school physician at their booked time
 * - served   = finished in the queue, or completed with the dentist/physician
 * - skipped  = called in the queue but not there (queue only)
 * - no_shows = confirmed bookings the patient never came to (any professional)
 * - bookings = confirmed bookings in the range (any professional)
 *
 * The nurse's own bookings come through her queue, so they are counted once,
 * as queue arrivals — never a second time as completed bookings.
 */
class DepartmentBreakdown
{
    private const METRICS = ['visited', 'served', 'skipped', 'no_shows', 'bookings'];

    /** Booking statuses that mean the booking was confirmed at some point. */
    private const CONFIRMED_STATUSES = ['booked', 'checked_in', 'completed', 'no_show'];

    /** Bucket for patients with no department on file (walk-ins before this change). */
    public const UNSPECIFIED = 'NONE';

    /**
     * @return array<string, mixed>
     */
    public function build(Carbon $from, Carbon $to, ?string $department = null): array
    {
        $counts = [];

        foreach ($this->queueCounts($from, $to, $department) as $row) {
            $key = $this->key($row->department, $row->course);
            $counts[$key]['visited'] = ($counts[$key]['visited'] ?? 0) + (int) $row->total;

            if ($row->status === 'done') {
                $counts[$key]['served'] = ($counts[$key]['served'] ?? 0) + (int) $row->total;
            } elseif ($row->status === 'skipped') {
                $counts[$key]['skipped'] = ($counts[$key]['skipped'] ?? 0) + (int) $row->total;
            }
        }

        foreach ($this->appointmentCounts($from, $to, $department) as $row) {
            $key = $this->key($row->department, $row->course);
            $total = (int) $row->total;
            $outsideQueue = in_array($row->profession, ['dentist', 'physician'], true);

            if (in_array($row->status, self::CONFIRMED_STATUSES, true)) {
                $counts[$key]['bookings'] = ($counts[$key]['bookings'] ?? 0) + $total;
            }

            if ($row->status === 'no_show') {
                $counts[$key]['no_shows'] = ($counts[$key]['no_shows'] ?? 0) + $total;
            }

            if ($outsideQueue && $row->status === 'completed') {
                $counts[$key]['visited'] = ($counts[$key]['visited'] ?? 0) + $total;
                $counts[$key]['served'] = ($counts[$key]['served'] ?? 0) + $total;
            }
        }

        $departments = $this->departmentRows($counts, $department);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'department' => $department,
            'totals' => $this->sum($departments),
            'departments' => $departments,
            'professionals' => $this->professionals($from, $to, $department),
        ];
    }

    /**
     * @return Collection<int, object{department: ?string, course: ?string, status: string, total: int}>
     */
    private function queueCounts(Carbon $from, Carbon $to, ?string $department): Collection
    {
        // Matched on Carbon instances: queue_date is a date column that the
        // app writes with a midnight time (see QueueEntry's date cast).
        return QueueEntry::query()
            ->join('users', 'users.id', '=', 'queue_entries.patient_id')
            ->whereBetween('queue_entries.queue_date', [$from->copy()->startOfDay(), $to->copy()->startOfDay()])
            ->when($department, fn ($query) => $query->where('users.department', $department))
            ->groupBy('users.department', 'users.course', 'queue_entries.status')
            ->selectRaw('users.department as department, users.course as course, queue_entries.status as status, COUNT(*) as total')
            ->toBase()
            ->get();
    }

    /**
     * @return Collection<int, object{department: ?string, course: ?string, status: string, profession: ?string, total: int}>
     */
    private function appointmentCounts(Carbon $from, Carbon $to, ?string $department): Collection
    {
        return Appointment::query()
            ->join('users as patients', 'patients.id', '=', 'appointments.patient_id')
            ->leftJoin('users as providers', 'providers.id', '=', 'appointments.provider_id')
            ->whereBetween('appointments.scheduled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($department, fn ($query) => $query->where('patients.department', $department))
            ->groupBy('patients.department', 'patients.course', 'appointments.status', 'providers.profession')
            ->selectRaw('patients.department as department, patients.course as course, appointments.status as status, providers.profession as profession, COUNT(*) as total')
            ->toBase()
            ->get();
    }

    /**
     * Every department (zeros included, so "nobody from SOE today" is an
     * answer too), busiest first, each with its courses.
     *
     * @param  array<string, array<string, int>>  $counts  keyed "DEPT|COURSE"
     * @return array<int, array<string, mixed>>
     */
    private function departmentRows(array $counts, ?string $onlyDepartment): array
    {
        $rows = [];

        foreach (Udd::departments() as $code => $info) {
            if ($onlyDepartment !== null && $code !== $onlyDepartment) {
                continue;
            }

            $courses = [];
            foreach ($info['courses'] as $courseCode => $courseName) {
                $courses[] = ['code' => $courseCode, 'name' => $courseName, ...$this->metrics($counts[$this->key($code, $courseCode)] ?? [])];
            }

            // Faculty and employees of the department have no course.
            $withoutCourse = $this->metrics($counts[$this->key($code, null)] ?? []);
            if (array_sum($withoutCourse) > 0) {
                $courses[] = ['code' => null, 'name' => 'Faculty and staff', ...$withoutCourse];
            }

            $rows[] = ['code' => $code, 'name' => $info['name'], ...$this->sum($courses), 'courses' => $courses];
        }

        $unspecified = $this->metrics($counts[$this->key(null, null)] ?? []);
        if ($onlyDepartment === null && array_sum($unspecified) > 0) {
            $rows[] = ['code' => self::UNSPECIFIED, 'name' => 'Department not recorded', ...$unspecified, 'courses' => []];
        }

        usort($rows, fn (array $a, array $b) => [$b['visited'], $b['bookings']] <=> [$a['visited'], $a['bookings']]);

        return $rows;
    }

    /**
     * How each professional's bookings went, and how many visit records
     * they wrote, for patients in the range (and department, if given).
     *
     * @return array<int, array<string, mixed>>
     */
    private function professionals(Carbon $from, Carbon $to, ?string $department): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $inDepartment = fn ($query) => $query->when(
            $department,
            fn ($q) => $q->whereIn('patient_id', User::query()->select('id')->where('department', $department)),
        );

        return User::providers()
            ->orderByRaw("CASE profession WHEN 'nurse' THEN 0 WHEN 'dentist' THEN 1 ELSE 2 END")
            ->get()
            ->map(function (User $provider) use ($start, $end, $inDepartment) {
                $statuses = Appointment::query()
                    ->where('provider_id', $provider->id)
                    ->whereBetween('scheduled_at', [$start, $end])
                    ->tap($inDepartment)
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status');

                $count = fn (array $keys): int => (int) collect($keys)->sum(fn (string $status) => $statuses[$status] ?? 0);

                return [
                    'id' => $provider->id,
                    'display_name' => $provider->display_name,
                    'job_title' => $provider->job_title,
                    'profession' => $provider->profession,
                    'bookings' => $count(self::CONFIRMED_STATUSES),
                    'completed' => $count(['completed']),
                    'no_shows' => $count(['no_show']),
                    'pending' => $count(['pending']),
                    'visits_recorded' => VisitRecord::query()
                        ->where('created_by_id', $provider->id)
                        ->whereBetween('created_at', [$start, $end])
                        ->tap($inDepartment)
                        ->count(),
                    'queue_served' => QueueEntry::query()
                        ->where('handled_by_id', $provider->id)
                        ->where('status', 'done')
                        ->whereBetween('queue_date', [$start, $end->copy()->startOfDay()])
                        ->tap($inDepartment)
                        ->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $raw
     * @return array<string, int>
     */
    private function metrics(array $raw): array
    {
        $metrics = [];

        foreach (self::METRICS as $metric) {
            $metrics[$metric] = $raw[$metric] ?? 0;
        }

        return $metrics;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function sum(array $rows): array
    {
        $totals = [];

        foreach (self::METRICS as $metric) {
            $totals[$metric] = array_sum(array_column($rows, $metric));
        }

        return $totals;
    }

    private function key(?string $department, ?string $course): string
    {
        // A course always implies its department, so a student whose
        // department was never stored still lands in the right school.
        $department ??= Udd::departmentOfCourse($course);

        return ($department ?? self::UNSPECIFIED).'|'.($course ?? '');
    }
}
