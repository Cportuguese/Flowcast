<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['date', 'label', 'note'])]
class EventDay extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
