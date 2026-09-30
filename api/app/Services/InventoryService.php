<?php

namespace App\Services;

use App\Models\InventoryLog;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\VisitRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function stockIn(
        Medicine $medicine,
        int $quantity,
        Carbon $expiryDate,
        ?string $batchNumber,
        ?int $userId,
        ?string $notes = null,
    ): MedicineBatch {
        return DB::transaction(function () use ($medicine, $quantity, $expiryDate, $batchNumber, $userId, $notes) {
            $batch = $medicine->batches()->create([
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
                'received_date' => today(),
                'quantity_received' => $quantity,
                'quantity_remaining' => $quantity,
            ]);

            InventoryLog::create([
                'medicine_id' => $medicine->id,
                'medicine_batch_id' => $batch->id,
                'user_id' => $userId,
                'type' => InventoryLog::TYPE_STOCK_IN,
                'quantity' => $quantity,
                'notes' => $notes,
            ]);

            return $batch;
        });
    }

    /**
     * FIFO by expiry: drains the earliest-expiring usable batch first and
     * rolls into the next one. Expired batches are never touched. Fails
     * as a whole (422) if the usable total is short.
     *
     * @return array<int, InventoryLog>
     */
    public function stockOut(
        Medicine $medicine,
        int $quantity,
        ?int $userId,
        ?VisitRecord $visit = null,
        ?string $notes = null,
    ): array {
        return DB::transaction(function () use ($medicine, $quantity, $userId, $visit, $notes) {
            $batches = $medicine->usableBatches()->lockForUpdate()->get();

            $available = (int) $batches->sum('quantity_remaining');
            if ($available < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Not enough stock for {$medicine->name} (available: {$available}).",
                ]);
            }

            $remaining = $quantity;
            $logs = [];

            foreach ($batches as $batch) {
                if ($remaining === 0) {
                    break;
                }

                $take = min($batch->quantity_remaining, $remaining);
                $batch->decrement('quantity_remaining', $take);

                $logs[] = InventoryLog::create([
                    'medicine_id' => $medicine->id,
                    'medicine_batch_id' => $batch->id,
                    'user_id' => $userId,
                    'type' => InventoryLog::TYPE_STOCK_OUT,
                    'quantity' => $take,
                    'visit_record_id' => $visit?->id,
                    'notes' => $notes,
                ]);

                $remaining -= $take;
            }

            return $logs;
        });
    }

    /**
     * Writes off whatever is left in an expired batch.
     */
    public function dispose(MedicineBatch $batch, ?int $userId, ?string $notes = null): ?InventoryLog
    {
        return DB::transaction(function () use ($batch, $userId, $notes) {
            $batch = MedicineBatch::lockForUpdate()->findOrFail($batch->id);

            if ($batch->quantity_remaining === 0) {
                return null;
            }

            $quantity = $batch->quantity_remaining;
            $batch->update(['quantity_remaining' => 0]);

            return InventoryLog::create([
                'medicine_id' => $batch->medicine_id,
                'medicine_batch_id' => $batch->id,
                'user_id' => $userId,
                'type' => InventoryLog::TYPE_DISPOSED,
                'quantity' => $quantity,
                'notes' => $notes ?? 'Expired, disposed',
            ]);
        });
    }
}
