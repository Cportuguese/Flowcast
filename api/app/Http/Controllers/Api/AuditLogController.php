<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Activity log listing, admin only. Filterable by date range, user,
     * action (partial match), and patient (by ID or name).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string'],
            'patient' => ['nullable', 'string'],
        ]);

        $query = AuditLog::with('user:id,name,role')->orderByDesc('created_at');

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['action'])) {
            $query->where('action', 'like', '%'.$validated['action'].'%');
        }

        if (! empty($validated['patient'])) {
            $this->filterByPatient($query, $validated['patient']);
        }

        return response()->json(
            $query->limit(200)->get()->map(fn (AuditLog $log) => $this->formatLog($log))->values()
        );
    }

    private function filterByPatient(Builder $query, string $patient): void
    {
        $patientIds = is_numeric($patient)
            ? collect([(int) $patient])
            : User::where('role', 'patient')->where('name', 'like', '%'.$patient.'%')->limit(20)->pluck('id');

        $query->where(function ($q) use ($patientIds) {
            $q->where(fn ($q2) => $q2->where('model_type', 'Appointment')->whereIn('model_id', Appointment::whereIn('patient_id', $patientIds)->pluck('id')))
                ->orWhere(fn ($q2) => $q2->where('model_type', 'QueueEntry')->whereIn('model_id', QueueEntry::whereIn('patient_id', $patientIds)->pluck('id')))
                ->orWhere(fn ($q2) => $q2->where('model_type', 'VisitRecord')->whereIn('model_id', VisitRecord::whereIn('patient_id', $patientIds)->pluck('id')))
                ->orWhere(fn ($q2) => $q2->where('model_type', 'User')->whereIn('model_id', $patientIds));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLog(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'role' => $log->user->role] : null,
            'action' => $log->action,
            'model_type' => $log->model_type,
            'model_id' => $log->model_id,
            'changes' => $log->changes,
            'created_at' => $log->created_at->toIso8601String(),
        ];
    }
}
