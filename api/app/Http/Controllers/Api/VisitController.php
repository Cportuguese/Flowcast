<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Medicine;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use App\Services\AppointmentNotifier;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Support\Udd;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VisitController extends Controller
{
    /**
     * Every relation formatVisit() reads, including the certificate's issuer
     * that CertificateController::format() reaches for. A listing endpoint
     * eager-loads this whole list, because loadMissing() inside the formatter
     * would otherwise fire four more queries per row.
     *
     * @var array<int, string>
     */
    private const VISIT_RELATIONS = [
        'patient:id,name,first_name,middle_name,last_name,school_id,school_id_verified_at,department,course,birthdate,sex,patient_type',
        'createdBy:id,name,role,profession',
        'inventoryLogs.medicine:id,name,unit',
        'certificate.issuedBy:id,name,role,profession',
    ];

    /** A search this broad (one department, say) shows the newest matches only. */
    private const SEARCH_LIMIT = 200;

    /**
     * Record a visit, staff only. A visit belongs to exactly one of:
     * - a queue entry: walk-ins and the nurse's booked patients, recorded
     *   by the head nurse; or
     * - an appointment: the dentist's or school physician's booked patient,
     *   recorded by that professional (or the head nurse). Saving it also
     *   completes the booking.
     *
     * Medicines dispensed during the visit ride along and are stocked out
     * FIFO in the same transaction, so a short shelf rejects the whole save
     * rather than leaving a record that claims meds were given.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'queue_entry_id' => ['required_without:appointment_id', 'prohibits:appointment_id', 'nullable', 'integer', 'exists:queue_entries,id', 'unique:visit_records,queue_entry_id'],
            'appointment_id' => ['required_without:queue_entry_id', 'nullable', 'integer', 'exists:appointments,id', 'unique:visit_records,appointment_id'],
            'complaint' => ['required', 'string'],
            'blood_pressure' => ['nullable', 'string', 'max:20'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'pulse' => ['nullable', 'integer', 'min:0', 'max:300'],
            'resp_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'intervention' => ['required', 'string'],
            'remarks' => ['nullable', 'string'],
            'referred_to' => ['nullable', 'string', 'max:255'],
            'medicines' => ['nullable', 'array'],
            'medicines.*.medicine_id' => ['required', 'integer', 'distinct', Rule::exists('medicines', 'id')->where('is_active', true)],
            'medicines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $appointment = null;

        if (! empty($validated['appointment_id'])) {
            $appointment = Appointment::with('provider:id,profession')->findOrFail($validated['appointment_id']);

            if ($user->role === 'personnel' && $appointment->provider_id !== $user->id) {
                abort(403, 'You can only record visits for your own patients.');
            }

            if ($appointment->goesThroughQueue()) {
                return response()->json(['message' => "The nurse's bookings are recorded from the queue."], 422);
            }

            if ($appointment->status !== 'booked') {
                return response()->json(['message' => 'Only a confirmed booking can be recorded as a visit.'], 422);
            }

            // Recording the visit completes the booking, so it waits for the day.
            if ($appointment->scheduled_at->isAfter(now()->endOfDay())) {
                return response()->json(['message' => 'This booking is for '.$appointment->scheduled_at->format('M j').'. Record the visit on that day.'], 422);
            }

            $patientId = $appointment->patient_id;
        } else {
            // The queue is the nurse's; the dentist and physician record
            // only the patients booked with them.
            if ($user->role === 'personnel') {
                abort(403, 'You can only record visits for your own patients.');
            }

            $patientId = QueueEntry::findOrFail($validated['queue_entry_id'])->patient_id;
        }

        $dispensed = $validated['medicines'] ?? [];
        unset($validated['medicines']);

        $visit = DB::transaction(function () use ($validated, $patientId, $appointment, $dispensed, $user) {
            $visit = VisitRecord::create([
                ...$validated,
                'patient_id' => $patientId,
                'created_by_id' => $user->id,
            ]);

            $inventory = app(InventoryService::class);
            foreach ($dispensed as $item) {
                $inventory->stockOut(
                    Medicine::findOrFail($item['medicine_id']),
                    $item['quantity'],
                    $user->id,
                    $visit,
                    'Dispensed during visit',
                );
            }

            $appointment?->update(['status' => 'completed']);

            return $visit;
        });

        app(AuditLogger::class)->log($user->id, 'visit.created', $visit, [
            'queue_entry_id' => $visit->queue_entry_id,
            'appointment_id' => $visit->appointment_id,
        ]);

        if ($appointment) {
            $notifier = app(AppointmentNotifier::class);
            $notifier->changed($appointment);
            $notifier->lineMoved($appointment);
        }

        return response()->json($this->formatVisit($visit), 201);
    }

    /**
     * A single visit record. Staff can view any; a patient can view only their own.
     */
    public function show(Request $request, VisitRecord $visit)
    {
        $this->abortUnlessStaffOrOwner($request->user(), $visit->patient_id);

        return response()->json($this->formatVisit($visit, forPatient: $request->user()->role === 'patient'));
    }

    /**
     * Staff search over visit records: by name (first, last or full),
     * middle name, date of birth, ID number, department, course and/or
     * visit date. At least one filter is required; the newest
     * SEARCH_LIMIT matches are returned.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            'birthdate' => ['nullable', 'date_format:Y-m-d'],
            'school_id' => ['nullable', 'string', 'max:32'],
            'department' => ['nullable', Rule::in(Udd::departmentCodes())],
            'course' => ['nullable', Rule::in(Udd::courseCodes())],
            'patient_id' => ['nullable', 'integer', 'exists:users,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filters = array_filter($validated, fn ($value) => $value !== null && $value !== '');

        if ($filters === []) {
            return response()->json(['message' => 'Enter at least one thing to search by: a name, birthdate, ID number, department or date.'], 422);
        }

        $patientFilters = array_intersect_key($filters, array_flip(['name', 'middle_name', 'birthdate', 'school_id', 'department', 'course']));

        $query = VisitRecord::with(self::VISIT_RELATIONS)->orderByDesc('created_at')->orderByDesc('id');

        if ($patientFilters !== []) {
            $query->whereIn('patient_id', $this->matchingPatients($patientFilters));
        }

        if (isset($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (isset($filters['date'])) {
            $query->whereDate('created_at', $filters['date']);
        }

        return response()->json($query->limit(self::SEARCH_LIMIT)->get()->map(fn (VisitRecord $visit) => $this->formatVisit($visit))->values());
    }

    /**
     * The logged-in patient's own visit summary history — what was done is
     * shown, the full record is in show().
     */
    public function mine(Request $request)
    {
        $visits = VisitRecord::with('createdBy:id,name,role,profession')
            ->where('patient_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($visits->map(fn (VisitRecord $visit) => [
            'id' => $visit->id,
            'date' => $visit->created_at->toIso8601String(),
            'complaint' => $visit->complaint,
            'intervention' => $visit->intervention,
            'seen_by' => $visit->createdBy->display_name,
            'seen_by_title' => $visit->createdBy->job_title,
        ])->values());
    }

    /**
     * Patient ids matching the search's person filters, as a subquery.
     *
     * @param  array<string, string>  $filters
     */
    private function matchingPatients(array $filters)
    {
        return User::query()
            ->select('id')
            ->where('role', 'patient')
            // "Juan Cruz" should find Juan Dela Cruz too, so every word typed
            // must appear somewhere in the full name.
            ->when($filters['name'] ?? null, function ($query, string $name) {
                foreach (preg_split('/\s+/u', trim($name)) ?: [] as $word) {
                    $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.self::likeLiteral($word).'%']);
                }
            })
            ->when($filters['middle_name'] ?? null, fn ($query, string $middle) => $query->whereRaw("middle_name LIKE ? ESCAPE '!'", ['%'.self::likeLiteral(trim($middle)).'%']))
            ->when($filters['birthdate'] ?? null, fn ($query, string $birthdate) => $query->whereDate('birthdate', $birthdate))
            // Partial IDs work from the start: "23-1057" finds 23-1057-651.
            ->when($filters['school_id'] ?? null, fn ($query, string $schoolId) => $query->whereRaw("school_id LIKE ? ESCAPE '!'", [self::likeLiteral(trim($schoolId)).'%']))
            ->when($filters['department'] ?? null, fn ($query, string $department) => $query->where('department', $department))
            ->when($filters['course'] ?? null, fn ($query, string $course) => $query->where('course', $course));
    }

    /**
     * What was typed, matched as typed: % and _ are LIKE wildcards, so a
     * search for "%" would otherwise match every patient and get round the
     * "at least one filter" rule. '!' is the escape character (declared in
     * each query's ESCAPE clause), since MySQL and SQLite disagree on a
     * default one.
     */
    private static function likeLiteral(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatVisit(VisitRecord $visit, bool $forPatient = false): array
    {
        $visit->loadMissing(self::VISIT_RELATIONS);

        // A dispense can span two batches (two log rows); show it once.
        $medicines = $visit->inventoryLogs
            ->groupBy('medicine_id')
            ->map(fn ($logs) => [
                'id' => $logs->first()->medicine->id,
                'name' => $logs->first()->medicine->name,
                'unit' => $logs->first()->medicine->unit,
                'quantity' => (int) $logs->sum('quantity'),
            ])
            ->values();

        $patient = $visit->patient;

        return [
            'id' => $visit->id,
            'queue_entry_id' => $visit->queue_entry_id,
            'appointment_id' => $visit->appointment_id,
            'date' => $visit->created_at->toIso8601String(),
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'first_name' => $patient->first_name,
                'middle_name' => $patient->middle_name,
                'last_name' => $patient->last_name,
                'school_id' => $patient->school_id,
                'id_verified' => $patient->hasVerifiedSchoolId(),
                'department' => $patient->department,
                'course' => $patient->course,
                'birthdate' => $patient->birthdate?->toDateString(),
                'sex' => $patient->sex,
                'patient_type' => $patient->patient_type,
            ],
            'created_by' => $visit->createdBy->display_name,
            'created_by_id' => $visit->created_by_id,
            'created_by_title' => $visit->createdBy->job_title,
            'certificate' => $visit->certificate ? CertificateController::format($visit->certificate, $forPatient) : null,
            'complaint' => $visit->complaint,
            'vitals' => [
                'blood_pressure' => $visit->blood_pressure,
                'temperature' => $visit->temperature,
                'pulse' => $visit->pulse,
                'resp_rate' => $visit->resp_rate,
            ],
            'intervention' => $visit->intervention,
            'remarks' => $visit->remarks,
            'referred_to' => $visit->referred_to,
            'medicines' => $medicines,
        ];
    }
}
