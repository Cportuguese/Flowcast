<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['medicine_id', 'batch_number', 'expiry_date', 'received_date', 'quantity_received', 'quantity_remaining'])]
class MedicineBatch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_date' => 'date',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date->lt(today());
    }
}
