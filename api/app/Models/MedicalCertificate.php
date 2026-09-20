<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'visit_record_id',
    'patient_id',
    'issued_by_id',
    'certificate_no',
    'rest_days',
    'remarks',
    'valid_from',
    'valid_until',
    'file_path',
])]
class MedicalCertificate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rest_days' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(VisitRecord::class, 'visit_record_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    /**
     * Next sequential number for the year, e.g. MC-2026-0042.
     * Called inside the issuing transaction so two nurses can't collide.
     */
    public static function nextNumber(int $year): string
    {
        $prefix = sprintf('MC-%d-', $year);
        $last = static::where('certificate_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('certificate_no')
            ->value('certificate_no');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
