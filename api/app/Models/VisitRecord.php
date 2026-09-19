<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'queue_entry_id',
    'patient_id',
    'created_by_id',
    'complaint',
    'blood_pressure',
    'temperature',
    'pulse',
    'resp_rate',
    'diagnosis',
    'treatment',
    'referred_to',
])]
class VisitRecord extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:1',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class);
    }
}
