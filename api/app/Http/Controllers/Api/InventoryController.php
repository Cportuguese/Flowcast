<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLog;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    private const EXPIRING_SOON_DAYS = 30;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Receive a delivery: one batch + one log. Staff, not admin-only — the
     * nurse is the one physically accepting stock.
     */
    public function stockIn(Request $request)
    {
        $validated = $request->validate([
            'medicine_id' => ['required', 'integer', Rule::exists('medicines', 'id')->where('is_active', true)],
            'quantity' => ['required', 'integer', 'min:1'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $medicine = Medicine::findOrFail($validated['medicine_id']);

        $batch = $this->inventory->stockIn(
            $medicine,
            $validated['quantity'],
            Carbon::createFromFormat('Y-m-d', $validated['expiry_date'])->startOfDay(),
            $validated['batch_number'] ?? null,
            $request->user()->id,
            $validated['notes'] ?? null,
        );

        return response()->json([
            'batch' => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date->toDateString(),
                'quantity_remaining' => $batch->quantity_remaining,
            ],
            'medicine' => MedicineController::formatMedicine($medicine->load('batches')),
        ], 201);
    }

    /**
     * Routine depletion (not tied to a visit — visit dispensing goes through
     * POST /visits). FIFO by expiry.
     */
    public function stockOut(Request $request)
    {
        $validated = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $medicine = Medicine::findOrFail($validated['medicine_id']);

        $this->inventory->stockOut(
            $medicine,
            $validated['quantity'],
            $request->user()->id,
            null,
            $validated['notes'] ?? null,
        );

        return response()->json([
            'medicine' => MedicineController::formatMedicine($medicine->load('batches')),
        ]);
    }

    public function lowStock()
    {
        $rows = Medicine::with('batches')->where('is_active', true)->orderBy('name')->get()
            ->map(fn (Medicine $medicine) => MedicineController::formatMedicine($medicine))
            ->filter(fn (array $row) => $row['status'] !== 'ok')
            ->map(fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'stock' => $row['stock'],
                'reorder_level' => $row['reorder_level'],
                'reorder_quantity' => $row['reorder_quantity'],
                'status' => $row['status'],
            ])
            ->values();

        return response()->json($rows);
    }

    public function expiringSoon()
    {
        $cutoff = today()->addDays(self::EXPIRING_SOON_DAYS);

        $batches = MedicineBatch::with('medicine:id,name,unit')
            ->where('quantity_remaining', '>', 0)
            ->whereDate('expiry_date', '>=', today())
            ->whereDate('expiry_date', '<=', $cutoff)
            ->orderBy('expiry_date')
            ->get();

        return response()->json([
            'days' => self::EXPIRING_SOON_DAYS,
            'batches' => $batches->map(fn (MedicineBatch $batch) => $this->formatBatch($batch))->values(),
        ]);
    }

    /**
     * Expired batches that still hold stock — admin disposes them from here.
     */
    public function expired()
    {
        $batches = MedicineBatch::with('medicine:id,name,unit')
            ->where('quantity_remaining', '>', 0)
            ->whereDate('expiry_date', '<', today())
            ->orderBy('expiry_date')
            ->get();

        return response()->json($batches->map(fn (MedicineBatch $batch) => $this->formatBatch($batch))->values());
    }

    public function disposeBatch(Request $request, MedicineBatch $batch)
    {
        if (! $batch->isExpired()) {
            return response()->json(['message' => 'Only expired batches can be disposed.'], 422);
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->inventory->dispose($batch, $request->user()->id, $validated['notes'] ?? null);

        return response()->json($this->formatBatch($batch->fresh('medicine')));
    }

    /**
     * Full transaction log, admin only. Filterable by date, medicine, type.
     */
    public function history(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'medicine_id' => ['nullable', 'integer', 'exists:medicines,id'],
            'type' => ['nullable', Rule::in(InventoryLog::TYPES)],
        ]);

        $query = InventoryLog::with([
            'medicine:id,name,unit',
            'batch:id,batch_number,expiry_date',
            'user:id,name',
            'visitRecord:id,queue_entry_id,patient_id',
            'visitRecord.patient:id,name',
        ])->orderByDesc('created_at')->orderByDesc('id');

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! empty($validated['medicine_id'])) {
            $query->where('medicine_id', $validated['medicine_id']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        return response()->json(
            $query->limit(500)->get()->map(fn (InventoryLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'type' => $log->type,
                'quantity' => $log->quantity,
                'medicine' => ['id' => $log->medicine->id, 'name' => $log->medicine->name, 'unit' => $log->medicine->unit],
                'batch' => $log->batch ? [
                    'id' => $log->batch->id,
                    'batch_number' => $log->batch->batch_number,
                    'expiry_date' => $log->batch->expiry_date->toDateString(),
                ] : null,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'visit' => $log->visitRecord ? [
                    'id' => $log->visitRecord->id,
                    'queue_entry_id' => $log->visitRecord->queue_entry_id,
                    'patient_name' => $log->visitRecord->patient?->name,
                ] : null,
                'notes' => $log->notes,
            ])->values()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBatch(MedicineBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'medicine' => ['id' => $batch->medicine->id, 'name' => $batch->medicine->name, 'unit' => $batch->medicine->unit],
            'batch_number' => $batch->batch_number,
            'expiry_date' => $batch->expiry_date->toDateString(),
            'received_date' => $batch->received_date->toDateString(),
            'quantity_remaining' => $batch->quantity_remaining,
            'days_until_expiry' => (int) today()->diffInDays($batch->expiry_date, false),
        ];
    }
}
