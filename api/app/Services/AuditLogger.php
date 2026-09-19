<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function log(?int $userId, string $action, ?Model $model = null, ?array $changes = null): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'model_type' => $model ? class_basename($model) : null,
            'model_id' => $model?->id,
            'changes' => $changes,
        ]);
    }
}
