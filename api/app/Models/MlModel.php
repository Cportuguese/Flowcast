<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single trained model version: its learned coefficients, the feature
 * scaling used to produce them, and how it scored on its holdout.
 */
#[Fillable([
    'key', 'version', 'algorithm', 'feature_names', 'coefficients', 'intercept',
    'feature_means', 'feature_stds', 'lambda', 'training_rows', 'holdout_rows',
    'metrics', 'residual_std', 'is_active', 'trained_at',
])]
class MlModel extends Model
{
    use HasFactory;

    /** Model key for the per-arrival wait time regression. */
    public const KEY_WAIT_TIME = 'wait_time';

    /** Model key for the daily arrival volume regression. */
    public const KEY_VOLUME = 'volume';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'feature_names' => 'array',
            'coefficients' => 'array',
            'feature_means' => 'array',
            'feature_stds' => 'array',
            'intercept' => 'float',
            'lambda' => 'float',
            'training_rows' => 'integer',
            'holdout_rows' => 'integer',
            'metrics' => 'array',
            'residual_std' => 'float',
            'is_active' => 'boolean',
            'trained_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<MlModel>  $query
     * @return Builder<MlModel>
     */
    public function scopeActiveKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key)->where('is_active', true);
    }

    /**
     * The currently serving model for a key, or null before the first
     * successful training run.
     */
    public static function active(string $key): ?self
    {
        return self::query()->activeKey($key)->latest('version')->first();
    }
}
