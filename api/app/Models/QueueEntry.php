<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['queue_number', 'queue_date', 'patient_id', 'source', 'status', 'appointment_id', 'handled_by_id', 'joined_at', 'called_at', 'near_call_notified_at', 'completed_at'])]
class QueueEntry extends Model
{
    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'joined_at' => 'datetime',
            'called_at' => 'datetime',
            'near_call_notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_id');
    }
}
