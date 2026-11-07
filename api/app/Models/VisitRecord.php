<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What happened at one clinic visit. A visit comes from exactly one of a
 * queue entry (walk-ins and the nurse's bookings) or an appointment (the
 * dentist's and the school physician's patients, seen at their booked time).
 */
#[Fillable([
    'queue_entry_id',
    'appointment_id',
    'patient_id',
    'created_by_id',
    'complaint',
    'blood_pressure',
    'temperature',
    'pulse',
    'resp_rate',
    'intervention',
    'remarks',
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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Stock-out rows for medicines dispensed during this visit.
     */
    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(MedicalCertificate::class);
    }
}
