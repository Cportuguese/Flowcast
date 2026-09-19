<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per batch touched. A single stock-out that spans two batches
 * writes two rows, so every row points at exactly the batch it changed.
 */
#[Fillable(['medicine_id', 'medicine_batch_id', 'user_id', 'type', 'quantity', 'visit_record_id', 'notes'])]
class InventoryLog extends Model
{
    use HasFactory;

    public const TYPE_STOCK_IN = 'stock_in';

    public const TYPE_STOCK_OUT = 'stock_out';

    public const TYPE_DISPOSED = 'disposed';

    public const TYPES = [self::TYPE_STOCK_IN, self::TYPE_STOCK_OUT, self::TYPE_DISPOSED];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'medicine_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visitRecord(): BelongsTo
    {
        return $this->belongsTo(VisitRecord::class);
    }
}
