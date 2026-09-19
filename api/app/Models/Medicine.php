<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category', 'unit', 'reorder_level', 'reorder_quantity', 'is_active'])]
class Medicine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    /**
     * Batches that can still be dispensed: stock left and not yet expired.
     * Ordered so the first row is the one FIFO takes from.
     */
    public function usableBatches(): HasMany
    {
        return $this->batches()
            ->where('quantity_remaining', '>', 0)
            ->whereDate('expiry_date', '>=', today())
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    public function expiredBatches(): HasMany
    {
        return $this->batches()
            ->where('quantity_remaining', '>', 0)
            ->whereDate('expiry_date', '<', today());
    }

    /**
     * Dispensable stock only — expired batches don't count until disposed.
     */
    public function currentStock(): int
    {
        return (int) $this->usableBatches()->sum('quantity_remaining');
    }
}
