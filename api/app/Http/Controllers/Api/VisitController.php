<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QueueEntry;
use App\Models\VisitRecord;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    /**
     * Create the visit record for a queue entry, personnel/admin only.
     * One record per queue entry (enforced by a unique constraint).
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
        ]);

        $queueEntry = QueueEntry::findOrFail($validated['queue_entry_id']);

        $visit = VisitRecord::create([
            ...$validated,
            'patient_id' => $queueEntry->patient_id,
            'created_by_id' => $request->user()->id,
        ]);

        return response()->json($this->formatVisit($visit), 201);
    }

    /**
     * A single visit record. Staff can view any; a patient can view only their own.
     */
    public function show(Request $request, QueueEntry $queueEntry)
    {
        $visit = VisitRecord::where('queue_entry_id', $queueEntry->id)->firstOrFail();

        $this->authorizeView($request, $visit);

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

        $query = VisitRecord::with('patient:id,name,school_id')->orderByDesc('created_at');

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

    private function authorizeView(Request $request, VisitRecord $visit): void
    {
        $user = $request->user();
        $isStaff = in_array($user->role, ['admin', 'personnel'], true);

        if (! $isStaff && $visit->patient_id !== $user->id) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatVisit(VisitRecord $visit): array
    {
        $visit->loadMissing('patient:id,name,school_id', 'createdBy:id,name');

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
        ];
    }
}
