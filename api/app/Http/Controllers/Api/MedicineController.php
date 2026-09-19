<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MedicineController extends Controller
{
    /**
     * Catalog with live stock, staff-visible. Archived medicines are hidden
     * unless ?include_inactive=1 (the admin catalog view).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $query = Medicine::with('batches')->orderBy('name');

        if (empty($validated['include_inactive'])) {
            $query->where('is_active', true);
        }

        return response()->json(
            $query->get()->map(fn (Medicine $medicine) => $this->formatMedicine($medicine))->values()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $medicine = Medicine::create($validated);

        app(AuditLogger::class)->log($request->user()->id, 'medicine.created', $medicine, [
            'name' => $medicine->name,
            'unit' => $medicine->unit,
            'reorder_level' => $medicine->reorder_level,
        ]);

        return response()->json($this->formatMedicine($medicine->load('batches')), 201);
    }

    /**
     * Edit details or archive/unarchive via is_active. No delete: batches
     * and logs must keep pointing at the medicine for history.
     */
    public function update(Request $request, Medicine $medicine)
    {
        $validated = $request->validate([
            ...$this->rules($medicine),
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $medicine->fill($validated);
        $changes = $medicine->getDirty();
        $medicine->save();

        if (! empty($changes)) {
            app(AuditLogger::class)->log($request->user()->id, 'medicine.updated', $medicine, $changes);
        }

        return response()->json($this->formatMedicine($medicine->load('batches')));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Medicine $medicine = null): array
    {
        $sometimes = $medicine ? 'sometimes' : 'required';

        return [
            'name' => [$sometimes, 'string', 'max:255', Rule::unique('medicines', 'name')->ignore($medicine)],
            'category' => ['nullable', 'string', 'max:255'],
            'unit' => [$sometimes, 'string', 'max:20'],
            'reorder_level' => [$sometimes, 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Computed from the eager-loaded batches so the catalog list stays a
     * single query. Mirrors Medicine::usableBatches()/currentStock().
     *
     * @return array<string, mixed>
     */
    public static function formatMedicine(Medicine $medicine): array
    {
        $today = today();

        $usable = $medicine->batches
            ->filter(fn (MedicineBatch $batch) => $batch->quantity_remaining > 0 && $batch->expiry_date->gte($today))
            ->sortBy([['expiry_date', 'asc'], ['id', 'asc']])
            ->values();

        $expiredQty = $medicine->batches
            ->filter(fn (MedicineBatch $batch) => $batch->quantity_remaining > 0 && $batch->expiry_date->lt($today))
            ->sum('quantity_remaining');

        $stock = (int) $usable->sum('quantity_remaining');

        return [
            'id' => $medicine->id,
            'name' => $medicine->name,
            'category' => $medicine->category,
            'unit' => $medicine->unit,
            'reorder_level' => $medicine->reorder_level,
            'reorder_quantity' => $medicine->reorder_quantity,
            'is_active' => $medicine->is_active,
            'stock' => $stock,
            'status' => $stock === 0 ? 'out' : ($stock <= $medicine->reorder_level ? 'low' : 'ok'),
            'next_expiry' => $usable->first()?->expiry_date->toDateString(),
            'expired_quantity' => (int) $expiredQty,
            'batches' => $usable->map(fn (MedicineBatch $batch) => [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date->toDateString(),
                'quantity_remaining' => $batch->quantity_remaining,
            ])->values(),
        ];
    }
}
