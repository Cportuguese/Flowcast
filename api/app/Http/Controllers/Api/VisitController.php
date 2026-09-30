<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\QueueEntry;
use App\Models\VisitRecord;
use App\Services\AuditLogger;
use App\Services\InventoryService;
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
        'patient:id,name,school_id',
        'createdBy:id,name',
        'inventoryLogs.medicine:id,name,unit',
        'certificate.issuedBy:id,name',
    ];

    /**
     * Create the visit record for a queue entry, personnel/admin only.
     * One record per queue entry (enforced by a unique constraint).
     *
     * Medicines dispensed during the visit ride along and are stocked out
     * FIFO in the same transaction, so a short shelf rejects the whole save
     * rather than leaving a record that claims meds were given.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'queue_entry_id' => ['required', 'integer', 'exists:queue_entries,id', 'unique:visit_records,queue_entry_id'],
            'complaint' => ['required', 'string'],
            'blood_pressure' => ['nullable', 'string', 'max:20'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'pulse' => ['nullable', 'integer', 'min:0', 'max:300'],
            'resp_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'diagnosis' => ['required', 'string'],
            'treatment' => ['nullable', 'string'],
            'referred_to' => ['nullable', 'string', 'max:255'],
            'medicines' => ['nullable', 'array'],
            'medicines.*.medicine_id' => ['required', 'integer', 'distinct', Rule::exists('medicines', 'id')->where('is_active', true)],
            'medicines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $queueEntry = QueueEntry::findOrFail($validated['queue_entry_id']);
        $dispensed = $validated['medicines'] ?? [];
        unset($validated['medicines']);

        $visit = DB::transaction(function () use ($validated, $queueEntry, $dispensed, $request) {
            $visit = VisitRecord::create([
                ...$validated,
                'patient_id' => $queueEntry->patient_id,
                'created_by_id' => $request->user()->id,
            ]);

            $inventory = app(InventoryService::class);
            foreach ($dispensed as $item) {
                $inventory->stockOut(
                    Medicine::findOrFail($item['medicine_id']),
                    $item['quantity'],
                    $request->user()->id,
                    $visit,
                    'Dispensed during visit',
                );
            }

            return $visit;
        });

        app(AuditLogger::class)->log($request->user()->id, 'visit.created', $visit, [
            'queue_entry_id' => $visit->queue_entry_id,
            'diagnosis' => $visit->diagnosis,
        ]);

        return response()->json($this->formatVisit($visit), 201);
    }

    /**
     * A single visit record. Staff can view any; a patient can view only their own.
     */
    public function show(Request $request, QueueEntry $queueEntry)
    {
        $visit = VisitRecord::where('queue_entry_id', $queueEntry->id)->firstOrFail();

        $this->abortUnlessStaffOrOwner($request->user(), $visit->patient_id);

        return response()->json($this->formatVisit($visit));
    }

    /**
     * Personnel/admin lookup by school ID, walk-in patient ID, and/or date.
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'school_id' => ['nullable', 'string'],
            'patient_id' => ['nullable', 'integer', 'exists:users,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (empty($validated['school_id']) && empty($validated['patient_id']) && empty($validated['date'])) {
            return response()->json(['message' => 'Provide at least one of school_id, patient_id, or date.'], 422);
        }

        $query = VisitRecord::with(self::VISIT_RELATIONS)->orderByDesc('created_at');

        if (! empty($validated['school_id'])) {
            $query->whereHas('patient', fn ($q) => $q->where('school_id', $validated['school_id']));
        }

        if (! empty($validated['patient_id'])) {
            $query->where('patient_id', $validated['patient_id']);
        }

        if (! empty($validated['date'])) {
            $query->whereDate('created_at', $validated['date']);
        }

        return response()->json($query->get()->map(fn (VisitRecord $visit) => $this->formatVisit($visit))->values());
    }

    /**
     * The logged-in patient's own visit summary history — diagnosis shown,
     * treatment details omitted from this list (full detail is in show()).
     */
    public function mine(Request $request)
    {
        $visits = VisitRecord::where('patient_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($visits->map(fn (VisitRecord $visit) => [
            'id' => $visit->id,
            'queue_entry_id' => $visit->queue_entry_id,
            'date' => $visit->created_at->toIso8601String(),
            'complaint' => $visit->complaint,
            'diagnosis' => $visit->diagnosis,
        ])->values());
    }

    /**
     * @return array<string, mixed>
     */
    private function formatVisit(VisitRecord $visit): array
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

        return [
            'id' => $visit->id,
            'queue_entry_id' => $visit->queue_entry_id,
            'date' => $visit->created_at->toIso8601String(),
            'patient' => [
                'id' => $visit->patient->id,
                'name' => $visit->patient->name,
                'school_id' => $visit->patient->school_id,
            ],
            'created_by' => $visit->createdBy->name,
            'certificate' => $visit->certificate ? CertificateController::format($visit->certificate) : null,
            'complaint' => $visit->complaint,
            'vitals' => [
                'blood_pressure' => $visit->blood_pressure,
                'temperature' => $visit->temperature,
                'pulse' => $visit->pulse,
                'resp_rate' => $visit->resp_rate,
            ],
            'diagnosis' => $visit->diagnosis,
            'treatment' => $visit->treatment,
            'referred_to' => $visit->referred_to,
            'medicines' => $medicines,
        ];
    }
}
